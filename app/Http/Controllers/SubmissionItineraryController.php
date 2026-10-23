<?php

namespace App\Http\Controllers;

use App\Models\FfSubmission;
use App\Models\Setting;
use App\Models\SubmissionItinerary;
use App\Models\WebsiteDocumentProfile;
use App\Services\SubmissionItineraryData;
use App\Services\SubmissionItineraryPdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SubmissionItineraryController extends Controller
{
    public function store(Request $request, FfSubmission $entry, SubmissionItineraryData $normalizer)
    {
        $this->authorize('update', $entry);
        $input = $request->validate([
            'reference_type' => ['required', Rule::in(['internal', 'external'])],
            'external_pnr' => ['exclude_unless:reference_type,external', 'required', 'string', 'max:20', 'regex:/\A[A-Za-z0-9][A-Za-z0-9-]{0,19}\z/D'],
            'booking_source' => ['exclude_unless:reference_type,external', 'required', 'string', 'max:120', 'regex:/\A[^\x00-\x1F\x7F]+\z/u'],
        ]);

        $itinerary = DB::transaction(function () use ($entry, $input, $normalizer, $request) {
            // Serialize generation for this submission, including its first reference.
            $entry = FfSubmission::whereKey($entry->id)->lockForUpdate()->firstOrFail();
            $snapshot = $normalizer->fromPayload($entry->payload ?? [], $entry->email);
            $segments = collect($snapshot['trips'])->flatMap(fn ($trip) => $trip['segments']);
            $hasRoute = $segments->contains(function ($segment) {
                $hasLocation = fn ($point) => ! empty($point['airport_code']) || ! empty($point['airport_name']) || ! empty($point['city']);

                return $hasLocation($segment['departure']) && $hasLocation($segment['arrival']);
            });
            if (empty($snapshot['passengers']) || ! $hasRoute) {
                throw ValidationException::withMessages(['itinerary' => 'This submission needs at least one passenger and flight route before an itinerary can be created.']);
            }
            // Reject unusually large documents instead of silently dropping facts.
            $tooLong = false;
            array_walk_recursive($snapshot, function ($value) use (&$tooLong) {
                $tooLong = $tooLong || (is_string($value) && mb_strlen($value) > 500);
            });
            if ($tooLong || count($snapshot['passengers']) > 100 || $segments->count() > 80 || strlen(json_encode($snapshot, JSON_THROW_ON_ERROR)) > 200000) {
                throw ValidationException::withMessages(['itinerary' => 'This submission is too large for one itinerary. Review the submitted fields or split the travel group before generating.']);
            }
            $profile = WebsiteDocumentProfile::where('website_id', $entry->website_id)->first();
            $snapshot['website'] = [
                'name' => $profile?->brand_name ?: $entry->website->name,
                'host' => parse_url($entry->website->base_url, PHP_URL_HOST) ?: '',
            ];
            $agency = Setting::get('itinerary_agency:'.$entry->website_id, []);
            $snapshot['agency'] = [];
            foreach (['consultant_name' => 120, 'name' => 180, 'address' => 500] as $field => $maxLength) {
                $value = is_array($agency) ? ($agency[$field] ?? null) : null;
                $snapshot['agency'][$field] = is_string($value) && mb_strlen($value) <= $maxLength ? trim($value) : null;
            }
            $snapshot['schema_version'] = 1;
            $itinerary = SubmissionItinerary::firstOrNew(['ff_submission_id' => $entry->id]);
            $itinerary->fill([
                'reference' => $itinerary->reference ?: 'ITN-'.strtoupper(Str::random(12)),
                'reference_type' => $input['reference_type'],
                'external_pnr' => $input['reference_type'] === 'external' ? strtoupper($input['external_pnr']) : null,
                'booking_source' => $input['reference_type'] === 'external' ? trim($input['booking_source']) : null,
                'template' => 'classic_v1',
                'snapshot' => $snapshot,
                'generated_by' => $request->user()->id,
                'generated_at' => now(),
            ])->save();

            return $itinerary;
        });

        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json(['itinerary' => $itinerary->summary(true)])->header('Cache-Control', 'private, no-store');
        }

        return to_route('submissions.entry-details', $entry)->with('success', 'Itinerary document is ready to download.');
    }

    public function download(FfSubmission $entry, SubmissionItineraryPdf $pdf)
    {
        $this->authorize('view', $entry);
        $itinerary = SubmissionItinerary::where('ff_submission_id', $entry->id)->firstOrFail();
        abort_unless($itinerary->template === 'classic_v1', 422, 'This document template is unavailable.');

        return response($pdf->render($itinerary->document()))->withHeaders([
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$itinerary->filename().'"',
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function retired(FfSubmission $entry)
    {
        $this->authorize('update', $entry);
        abort(410, 'Amadeus generation has been retired. Use the itinerary document instead.');
    }

    public function legacyDownload(FfSubmission $entry)
    {
        $this->authorize('downloadPdf', $entry);
        // Retain existing downloads; never call a retired booking API on a GET.
        $path = $entry->pnr_pdf_path;
        abort_unless(is_string($path) && str_starts_with($path, 'dummy-tickets/') && ! str_contains($path, '..') && ! str_contains($path, '\\') && Storage::disk('public')->exists($path), 404);

        return Storage::disk('public')->download($path, 'Legacy-itinerary.pdf', ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
