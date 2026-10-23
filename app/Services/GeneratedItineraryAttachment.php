<?php

namespace App\Services;

use App\Models\FfSubmission;
use App\Models\OrderDocument;
use App\Models\SubmissionItinerary;
use App\Models\User;
use App\Models\WcOrder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class GeneratedItineraryAttachment
{
    public function context(WcOrder $order, User $actor, ?int $submissionId = null): array
    {
        Gate::forUser($actor)->authorize('view', $order);
        try {
            [$entry, $itinerary] = $this->source($order, $actor, $submissionId);
        } catch (ValidationException $exception) {
            return ['available' => false, 'reason' => $exception->errors()['itinerary'][0], 'source' => null];
        } catch (AuthorizationException) {
            return ['available' => false, 'reason' => 'You do not have access to the linked submission.', 'source' => null];
        }

        return ['available' => Gate::forUser($actor)->allows('update', $order),
            'reason' => Gate::forUser($actor)->allows('update', $order) ? null : 'You cannot attach documents to this order.',
            'source' => ['submission_id' => $entry->id, 'submission_number' => $entry->entry_id,
                'reference' => $itinerary->reference, 'reference_type' => $itinerary->reference_type,
                'external_pnr' => $itinerary->external_pnr, 'generated_at' => $itinerary->generated_at->toIso8601String(),
                'source_version' => $this->version($itinerary), 'name' => $itinerary->filename(),
                'download_url' => route('submissions.itinerary.download', $entry, false)]];
    }

    public function attach(WcOrder $order, User $actor, array $values): OrderDocument
    {
        Gate::forUser($actor)->authorize('update', $order);

        $created = null;
        try {
            return DB::transaction(function () use ($order, $actor, $values, &$created) {
                $locked = WcOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('update', $locked);
                [$entry, $itinerary] = $this->source($locked, $actor, (int) $values['submission_id'], true);
                $version = $this->version($itinerary);
                if (! hash_equals($version, $values['source_version'])) {
                    $this->invalid('The saved itinerary changed. Refresh the email composer and add the current version.');
                }
                if (empty($values['approved']) || $this->email($values['intended_customer_email']) !== $this->email($locked->customer_email)) {
                    $this->invalid('Confirm that this itinerary belongs to the order’s recorded customer before attaching it.');
                }
                $existing = OrderDocument::where('wc_order_id', $locked->id)->where('website_id', $locked->website_id)
                    ->where('submission_itinerary_id', $itinerary->id)->where('source_version', $version)->first();
                if ($existing) {
                    if ($existing->approved_at === null || $existing->approved_by === null || $this->email($existing->customer_email) !== $this->email($locked->customer_email)) {
                        $this->invalid('The previous itinerary attachment is no longer approved for this customer. Review the order first.');
                    }
                    app(OrderDocumentStorage::class)->bytes($existing);

                    return $existing;
                }
                $bytes = app(SubmissionItineraryPdf::class)->render($itinerary->document());
                if (! str_starts_with($bytes, '%PDF-') || strlen($bytes) > OrderEmailComposer::LIMITS['max_file_bytes']) {
                    $this->invalid('The generated itinerary is not a valid PDF within the 5 MiB attachment limit.');
                }
                // Reuse the same private-storage approval and content checks as uploads.
                $temporary = tempnam(sys_get_temp_dir(), 'wph-itinerary-');
                if ($temporary === false) {
                    $this->invalid('Temporary document storage is unavailable. Try attaching the itinerary again.');
                }
                try {
                    if (file_put_contents($temporary, $bytes) !== strlen($bytes)) {
                        $this->invalid('The itinerary could not be saved. Try attaching it again.');
                    }
                    $file = new UploadedFile($temporary, $itinerary->filename(), 'application/pdf', null, true);
                    $summaries = app(OrderDocumentStorage::class)->upload($locked, $actor, [$file], $locked->customer_email, true);
                    $document = OrderDocument::findOrFail($summaries[0]['id']);
                    $created = ['disk' => $document->disk, 'path' => $document->path];
                    $document->update(['submission_itinerary_id' => $itinerary->id, 'source_version' => $version,
                        'source_metadata' => ['submission_id' => $entry->id, 'reference' => $itinerary->reference]]);

                    return $document;
                } finally {
                    @unlink($temporary);
                }
            }, 1);
        } catch (Throwable $exception) {
            if ($created !== null) {
                Storage::disk($created['disk'])->delete($created['path']);
            }
            throw $exception;
        }
    }

    public function selectedDocuments(WcOrder $order, User $actor, array $ids): Collection
    {
        Gate::forUser($actor)->authorize('update', $order);
        $documents = OrderDocument::whereIn('id', $ids)->where('website_id', $order->website_id)->where('wc_order_id', $order->id)
            ->where('customer_email', strtolower($order->customer_email ?? ''))->whereNotNull('approved_at')->whereNotNull('approved_by')->orderBy('id')->get();
        if (count($ids) !== count(array_unique($ids)) || $documents->count() !== count($ids)) {
            throw ValidationException::withMessages(['document_ids' => 'Select approved documents belonging to this exact order and customer.']);
        }
        $this->assertCurrent($order, $actor, $documents);

        return $documents;
    }

    public function assertCurrent(WcOrder $order, User $actor, iterable $documents): void
    {
        $source = null;
        foreach ($documents as $document) {
            if ($document->source_version === null) {
                continue;
            }
            $source ??= $this->source($order, $actor);
            [$entry, $itinerary] = $source;
            if ((int) $document->submission_itinerary_id !== $itinerary->id
                || (int) ($document->source_metadata['submission_id'] ?? 0) !== $entry->id
                || ! hash_equals($document->source_version, $this->version($itinerary))) {
                $this->invalid('A selected itinerary changed. Add the current itinerary and prepare a new email preview.');
            }
        }
    }

    public function assertSnapshot(WcOrder $order, User $actor, array $snapshot): void
    {
        // Ordinary uploads retain their existing reviewed-MIME behavior. Only generated
        // sources need an additional freshness check; never download PDF bytes at send.
        $saved = array_values(array_filter($snapshot['attachments'] ?? [], fn ($attachment) => isset($attachment['id'], $attachment['source_version'])));
        if ($saved === []) {
            return;
        }
        $actor = $actor->fresh() ?? throw new AuthorizationException('The sending user is no longer available.');
        $documents = $this->selectedDocuments($order, $actor, array_column($saved, 'id'));
        foreach ($saved as $attachment) {
            $document = $documents->firstWhere('id', $attachment['id']);
            if (! hash_equals($document->sha256, $attachment['sha256'] ?? '') || $document->version !== ($attachment['version'] ?? null)
                || $document->name !== ($attachment['name'] ?? null) || $document->size !== ($attachment['size'] ?? null)
                || $document->source_version !== $attachment['source_version']) {
                $this->invalid('An approved attachment changed. Prepare a new email preview.');
            }
        }
    }

    private function source(WcOrder $order, User $actor, ?int $submissionId = null, bool $lock = false): array
    {
        $metadata = $order->payload['meta_data'] ?? [];
        $ids = collect(is_array($metadata) ? $metadata : [])->filter(fn ($meta) => is_array($meta) && ($meta['key'] ?? null) === '_fluent_id')
            ->map(fn ($meta) => is_int($meta['value'] ?? null) || is_string($meta['value'] ?? null) ? (string) $meta['value'] : null)->unique()->values();
        if ($ids->count() !== 1 || ! is_string($ids->first()) || ! preg_match('/\A[1-9][0-9]*\z/D', $ids->first())) {
            $this->invalid('This order does not have one unambiguous linked form submission.');
        }
        $query = FfSubmission::where('website_id', $order->website_id)->where('entry_id', $ids->first());
        $entries = ($lock ? $query->lockForUpdate() : $query)->limit(2)->get();
        if ($entries->count() !== 1 || ($submissionId !== null && $entries->first()->id !== $submissionId)) {
            $this->invalid('The submission link is missing, ambiguous or belongs to another order.');
        }
        $entry = $entries->first();
        Gate::forUser($actor)->authorize('view', $entry);
        $orders = WcOrder::where('website_id', $order->website_id);
        if ($orders->getConnection()->getDriverName() === 'sqlite') {
            $meta = "CASE WHEN metadata.type = 'object' THEN metadata.value ELSE '{}' END";
            $orders->whereRaw("EXISTS (SELECT 1 FROM json_each(CASE WHEN json_type(payload, '$.meta_data') = 'array' THEN json_extract(payload, '$.meta_data') ELSE '[]' END) AS metadata WHERE json_extract({$meta}, '$.key') = ? AND json_type({$meta}, '$.value') IN ('integer', 'text') AND CAST(json_extract({$meta}, '$.value') AS TEXT) = ?)", ['_fluent_id', (string) $entry->entry_id]);
        } else {
            $orders->where(fn ($match) => $match->whereJsonContains('payload->meta_data', [['key' => '_fluent_id', 'value' => (string) $entry->entry_id]])
                ->orWhereJsonContains('payload->meta_data', [['key' => '_fluent_id', 'value' => (int) $entry->entry_id]]));
        }
        $matches = $orders->limit(2)->pluck('id');
        if ($matches->count() !== 1 || $matches->first() !== $order->id) {
            $this->invalid('More than one order links to this submission. Resolve the link before attaching its itinerary.');
        }
        $query = SubmissionItinerary::where('ff_submission_id', $entry->id);
        $itinerary = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $itinerary) {
            $this->invalid('Generate and review an itinerary in the linked submission first.');
        }
        if ($itinerary->template !== 'classic_v1') {
            $this->invalid('This itinerary template is not supported. Generate an itinerary with the current template first.');
        }
        $customer = $this->email($order->customer_email);
        $emails = [$entry->email, $itinerary->snapshot['contact']['email'] ?? null,
            app(SubmissionItineraryData::class)->fromPayload($entry->payload ?? [], $entry->email)['contact']['email'] ?? null];
        $known = array_values(array_filter($emails, fn ($value) => $value !== null && $value !== ''));
        if ($customer === null || $known === [] || collect($known)->contains(fn ($value) => $this->email($value) !== $customer)) {
            $this->invalid('The submission or saved itinerary customer does not match the order’s recorded email. Correct the customer details before attaching.');
        }

        return [$entry, $itinerary];
    }

    private function version(SubmissionItinerary $itinerary): string
    {
        return hash('sha256', json_encode([SubmissionItineraryPdf::RENDER_VERSION, $itinerary->id, $itinerary->template, $itinerary->document()], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function email(mixed $value): ?string
    {
        return is_string($value) && filter_var(trim($value), FILTER_VALIDATE_EMAIL) ? strtolower(trim($value)) : null;
    }

    private function invalid(string $message): never
    {
        throw ValidationException::withMessages(['itinerary' => $message]);
    }
}
