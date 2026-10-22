<?php

namespace App\Services;

use App\Models\OrderDocument;
use App\Models\User;
use App\Models\WcOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderDocumentStorage
{
    public function upload(WcOrder $order, User $actor, array $files, string $customerEmail, bool $approved): array
    {
        Gate::forUser($actor)->authorize('update', $order);
        if (! $order->customer_email || strcasecmp($customerEmail, $order->customer_email) !== 0 || ! $approved) {
            throw ValidationException::withMessages(['documents' => 'Confirm that these PDFs belong to this order and its recorded customer before approving them.']);
        }
        $disk = (string) config('document_delivery.disk');
        if ($disk === 'public' || config("filesystems.disks.$disk.visibility") === 'public'
            || ! in_array(config("filesystems.disks.$disk.driver"), ['local', 's3'], true)
            || (config("filesystems.disks.$disk.driver") === 'local'
                && PrivateStoragePath::isPublic((string) config("filesystems.disks.$disk.root", '')))) {
            throw ValidationException::withMessages(['documents' => 'Configure a private document storage disk before uploading.']);
        }
        if (count($files) < 1 || count($files) > OrderEmailComposer::LIMITS['max_files']) {
            throw ValidationException::withMessages(['files' => 'Choose one to five PDFs.']);
        }
        $contents = [];
        $total = 0;
        foreach ($files as $file) {
            if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() < 1
                || $file->getSize() > OrderEmailComposer::LIMITS['max_file_bytes'] || $file->getMimeType() !== 'application/pdf') {
                throw ValidationException::withMessages(['files' => 'Each file must be a genuine PDF no larger than 5 MiB.']);
            }
            $bytes = $file->getContent();
            if (! str_starts_with($bytes, '%PDF-')) {
                throw ValidationException::withMessages(['files' => 'A selected file is not a PDF.']);
            }
            $total += strlen($bytes);
            if ($total > OrderEmailComposer::LIMITS['max_total_bytes']) {
                throw ValidationException::withMessages(['files' => 'The PDFs must total no more than 10 MiB.']);
            }
            $base = pathinfo(basename(str_replace('\\', '/', $file->getClientOriginalName())), PATHINFO_FILENAME);
            $name = mb_substr(preg_replace('/[^\pL\pN ._()-]/u', '-', $base), 0, 100);
            $contents[] = ['bytes' => $bytes, 'name' => (trim($name, ' .-') ?: 'Reservation').'.pdf'];
        }
        $paths = [];
        try {
            return DB::transaction(function () use ($order, $actor, $customerEmail, $disk, $contents, &$paths) {
                $locked = WcOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                Gate::forUser($actor)->authorize('update', $locked);
                if ($locked->website_id !== $order->website_id || strcasecmp($locked->customer_email ?? '', $customerEmail) !== 0) {
                    throw ValidationException::withMessages(['documents' => 'The order or customer changed. Reload before uploading.']);
                }
                $version = (int) OrderDocument::where('wc_order_id', $order->id)->max('version');
                $documents = [];
                foreach ($contents as $content) {
                    $id = (string) Str::uuid();
                    $path = 'order-documents/'.$order->website_id.'/'.$order->id.'/'.$id.'.pdf';
                    if (! Storage::disk($disk)->put($path, $content['bytes'], ['visibility' => 'private'])) {
                        throw ValidationException::withMessages(['documents' => 'Private storage is unavailable. No documents were approved.']);
                    }
                    $paths[] = $path;
                    $documents[] = OrderDocument::create([
                        'id' => $id, 'website_id' => $order->website_id, 'wc_order_id' => $order->id,
                        'uploaded_by' => $actor->id, 'approved_by' => $actor->id, 'approved_at' => now(),
                        'customer_email' => strtolower($customerEmail), 'name' => $content['name'], 'disk' => $disk, 'path' => $path,
                        'sha256' => hash('sha256', $content['bytes']), 'size' => strlen($content['bytes']), 'version' => ++$version,
                    ])->summary();
                }

                return $documents;
            }, 1);
        } catch (Throwable $exception) {
            foreach ($paths as $path) {
                Storage::disk($disk)->delete($path);
            }
            throw $exception;
        }
    }

    public function bytes(OrderDocument $document): string
    {
        $bytes = Storage::disk($document->disk)->get($document->path);
        if (! is_string($bytes) || ! str_starts_with($bytes, '%PDF-') || strlen($bytes) !== $document->size
            || ! hash_equals($document->sha256, hash('sha256', $bytes))) {
            throw ValidationException::withMessages(['documents' => 'An approved PDF is missing or has changed. Upload and approve a new version.']);
        }

        return $bytes;
    }
}
