<?php

// Isolated Linux Cloud CLI/R2 capacity check. No email, queue dispatch or store HTTP.
// Uses only fictional order 900002 created by cloud-staging-probe.php; keeps 900001 intact.
// APP_ENV=staging php -d disable_functions=mail tools/validation/cloud-capacity-probe.php --expected-app-url=https://APP.laravel.cloud --expected-bucket=BUCKET

use App\Models\GmailConnection;
use App\Models\OrderDocument;
use App\Models\OrderEmailDelivery;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Services\DocumentValidationSafety;
use App\Services\OrderDocumentStorage;
use App\Services\OrderEmailComposer;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mime\Email;

const CCP_MARKER = 'wphub-cloud-capacity-v1';
const CCP_BYTES_PER_FILE = 5242880;

final class CloudCapacityAssertion extends RuntimeException {}

function ccAssert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new CloudCapacityAssertion($message);
    }
}

function ccJson(array $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
}

function ccBoundaryPdf(int $target): string
{
    // Same strict-parser-verified algorithm as the isolated local capacity probe.
    // Padding is inside a content-stream comment; xref offsets and EOF stay valid.
    $padding = $target - 1000;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $stream = "BT /F1 18 Tf 40 780 Td (FICTIONAL Cloud capacity sample) Tj 0 -30 Td /F1 12 Tf (Not a reservation or travel document.) Tj ET\n%".str_repeat(' ', $padding)."\n";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Count 1 /Kids [3 0 R] >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
            '<< /Length '.strlen($stream)." >>\nstream\n".$stream.'endstream',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $body) {
            $offsets[] = strlen($pdf);
            $pdf .= ($index + 1)." 0 obj\n".$body."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        foreach (array_slice($offsets, 1) as $offset) {
            $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        if (strlen($pdf) === $target) {
            return $pdf;
        }
        $padding += $target - strlen($pdf);
    }
    throw new CloudCapacityAssertion('Boundary PDF did not reach exact size');
}

function ccRss(): array
{
    $status = is_readable('/proc/self/status') ? file_get_contents('/proc/self/status') : false;
    if (is_string($status)) {
        preg_match('/^VmRSS:\s+(\d+)\s+kB$/m', $status, $current);
        preg_match('/^VmHWM:\s+(\d+)\s+kB$/m', $status, $peak);

        return ['source' => '/proc/self/status', 'rss_bytes' => isset($current[1]) ? ((int) $current[1] * 1024) : null,
            'peak_rss_bytes' => isset($peak[1]) ? ((int) $peak[1] * 1024) : null];
    }

    return ['source' => null, 'rss_bytes' => null, 'peak_rss_bytes' => null];
}

$stage = 'arguments';
$temporaryFiles = [];
$failed = false;
$started = microtime(true);
try {
    ccAssert(PHP_SAPI === 'cli', 'CLI only');
    $options = getopt('', ['expected-app-url:', 'expected-bucket:', 'help']);
    if (isset($options['help'])) {
        ccJson(['usage' => 'APP_ENV=staging php -d disable_functions=mail tools/validation/cloud-capacity-probe.php --expected-app-url=https://APP.laravel.cloud --expected-bucket=BUCKET',
            'scope' => 'Linux Cloud CLI/R2 only. Two 5 MiB fictional PDFs on existing order 900002, immutable encrypted MIME and Gmail JSON serialization; no sending, HTTP uploads, store calls or queue jobs.',
            'repeat' => 'Recorded PDFs and delivery are reused without reupload or MIME rewriting; ambiguous partial state is refused.']);
        exit(0);
    }
    $origin = rtrim((string) ($options['expected-app-url'] ?? ''), '/');
    $bucket = (string) ($options['expected-bucket'] ?? '');
    ccAssert((bool) preg_match('#^https://[a-z0-9-]+\.laravel\.cloud$#D', $origin), 'Expected approved Cloud app URL required');
    ccAssert((bool) preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket), 'Expected approved bucket required');
    ccAssert(getenv('APP_ENV') === 'staging', 'Process APP_ENV must explicitly be staging');
    ccAssert(in_array('mail', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true), 'Run PHP with -d disable_functions=mail');
    ccAssert(PHP_OS_FAMILY === 'Linux', 'Actual Linux Cloud runtime required');

    $stage = 'bootstrap';
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $stage = 'isolation';
    ccAssert($app->environment('staging') && rtrim((string) config('app.url'), '/') === $origin && ! config('app.debug'), 'Approved staging URL/environment and debug=false required');
    $safety = app(DocumentValidationSafety::class);
    ccAssert($safety->enabled() && $safety->capture() && config('document_delivery.validation.store_origins') === [], 'Capture validation with empty store allowlist required');
    Http::preventStrayRequests();
    ccAssert(config('document_delivery.disk') === 'documents_private', 'Exact documents_private disk required');
    $disk = config('filesystems.disks.documents_private', []);
    ccAssert(($disk['driver'] ?? '') === 's3' && ($disk['bucket'] ?? '') === $bucket, 'Expected actual R2 disk/bucket required');
    ccAssert(empty($disk['root']) && empty($disk['prefix']) && empty($disk['url']) && ($disk['visibility'] ?? 'private') !== 'public', 'Private unprefixed disk without asset URL required');
    ccAssert((bool) preg_match('#^https://[a-z0-9]+(?:\.(?:eu|fedramp))?\.r2\.cloudflarestorage\.com$#D', rtrim((string) ($disk['endpoint'] ?? ''), '/')), 'HTTPS R2 endpoint required');
    ccAssert(DB::connection()->getDriverName() === 'mysql' && DB::selectOne('select database() as name')->name === 'documents_staging', 'Exact documents_staging MySQL schema required');
    ccAssert(OrderEmailComposer::LIMITS['max_file_bytes'] === CCP_BYTES_PER_FILE && OrderEmailComposer::LIMITS['max_total_bytes'] === CCP_BYTES_PER_FILE * 2, 'Expected application upload limits changed');

    $stage = 'fixture';
    $site = Website::where('slug', 'fictional-cloud-documents-probe')->firstOrFail();
    ccAssert($site->status === 'paused' && $site->base_url === 'https://cloud-probe.example.invalid', 'Known paused fictional website required');
    $owner = User::findOrFail($site->user_id);
    ccAssert(! $owner->is_admin && $owner->email === 'cloud-probe-owner@example.invalid', 'Fictional non-admin owner required');
    $order = WcOrder::where('website_id', $site->id)->where('wp_order_id', 900002)->firstOrFail();
    ccAssert(($order->payload['staging_probe'] ?? '') === 'wphub-cloud-documents-v1' && $order->status === 'processing'
        && $order->customer_email === 'cloud-probe-recipient@example.invalid', 'Exact fictional cross-order required');
    $original = WcOrder::where('website_id', $site->id)->where('wp_order_id', 900001)->firstOrFail();
    $originalDocument = OrderDocument::where('wc_order_id', $original->id)->sole();
    $originalIdentity = [$originalDocument->id, $originalDocument->sha256, $originalDocument->version];
    $gmail = GmailConnection::where('user_id', $owner->id)->firstOrFail();
    ccAssert($gmail->connected_at === null && $gmail->access_token === null && $gmail->refresh_token === null
        && $gmail->email === 'cloud-probe-sender@example.invalid', 'Disconnected fictional Gmail metadata required');
    $baselineJobs = DB::table('jobs')->count();
    $baselineFailedJobs = DB::table('failed_jobs')->count();

    $memoryBefore = memory_get_usage(true);
    $rssBefore = ccRss();
    $workStarted = microtime(true);
    $stage = 'boundary-pdfs';
    $boundaryPdf = ccBoundaryPdf(CCP_BYTES_PER_FILE);
    $expectedSha = hash('sha256', $boundaryPdf);
    $smallPdf = ccBoundaryPdf(1024);
    foreach ([$boundaryPdf, $smallPdf] as $content) {
        $path = tempnam(sys_get_temp_dir(), 'wphub-ccp-');
        ccAssert(is_string($path) && file_put_contents($path, $content) !== false, 'Temporary PDF write failed');
        chmod($path, 0600);
        $temporaryFiles[] = $path;
    }
    $upload = static fn (string $name, int $index = 0) => new UploadedFile($temporaryFiles[$index], $name, 'application/pdf', null, true);
    $marker = $order->payload['capacity_probe'] ?? null;
    $newlyUploaded = false;
    if ($marker === null) {
        ccAssert(! OrderDocument::where('wc_order_id', $order->id)->exists()
            && ! OrderEmailDelivery::where('wc_order_id', $order->id)->exists(), 'Unmarked capacity state exists; inspect without reuploading');
        $stage = 'real-10-mib-upload';
        // The production upload service provides approval and immutable version/hash rows.
        $documents = app(OrderDocumentStorage::class)->upload($order, $owner,
            [$upload('Fictional capacity A.pdf'), $upload('Fictional capacity B.pdf')], $order->customer_email, true);
        $newlyUploaded = true;
        $stage = 'immutable-mime';
        $email = (new Email)->from('cloud-probe-sender@example.invalid')->to('cloud-probe-recipient@example.invalid')
            ->subject('[FICTIONAL CLOUD CAPACITY] No email sent')->text('Fictional Cloud capacity sample. Not a reservation or travel document.')
            ->html('<p>Fictional Cloud capacity sample. Not a reservation or travel document.</p>');
        foreach ($documents as $summary) {
            $document = OrderDocument::findOrFail($summary['id']);
            $email->attach(app(OrderDocumentStorage::class)->bytes($document), $document->name, 'application/pdf');
        }
        $mime = $email->toString();
        $mimeSha = hash('sha256', $mime);
        // Only this fixture's metadata is created. Existing delivery MIME is never updated.
        $marker = DB::transaction(function () use ($order, $owner, $site, $gmail, $documents, $mime, $mimeSha) {
            $locked = WcOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
            ccAssert(! isset($locked->payload['capacity_probe']), 'Concurrent capacity fixture creation detected');
            $delivery = OrderEmailDelivery::create(['user_id' => $owner->id, 'wc_order_id' => $order->id, 'website_id' => $site->id,
                'gmail_connection_id' => $gmail->id, 'connection_key' => $gmail->connection_key, 'fingerprint' => hash('sha256', CCP_MARKER),
                'snapshot' => ['marker' => CCP_MARKER, 'documents' => $documents, 'mime_sha256' => $mimeSha], 'mime' => $mime,
                'status' => 'prepared', 'send_attempts' => 0, 'expires_at' => now()->addDays(2), 'workflow' => true,
                'document_mode' => 'partial', 'sync_status' => 'not_requested']);
            $saved = ['marker' => CCP_MARKER, 'delivery_id' => $delivery->id, 'document_ids' => array_column($documents, 'id'),
                'mime_sha256' => $mimeSha, 'mime_bytes' => strlen($mime), 'created_at' => gmdate(DATE_ATOM)];
            $payload = $locked->payload;
            $payload['capacity_probe'] = $saved;
            WcOrder::withoutEvents(fn () => $locked->update(['payload' => $payload]));

            return $saved;
        });
    }

    $stage = 'immutable-readback';
    ccAssert(($marker['marker'] ?? '') === CCP_MARKER && count($marker['document_ids'] ?? []) === 2, 'Known capacity marker required');
    $documents = OrderDocument::where('wc_order_id', $order->id)->orderBy('version')->get();
    ccAssert($documents->count() === 2 && $documents->pluck('id')->all() === $marker['document_ids'], 'Capacity document set changed');
    $verified = [];
    foreach ($documents as $index => $document) {
        ccAssert($document->disk === 'documents_private' && $document->size === CCP_BYTES_PER_FILE && $document->sha256 === $expectedSha
            && $document->version === $index + 1 && $document->approved_at !== null, 'Capacity document size/hash/approval changed');
        $read = app(OrderDocumentStorage::class)->bytes($document);
        ccAssert(hash('sha256', $read) === $expectedSha, 'Capacity R2 read/hash mismatch');
        $verified[] = ['id' => $document->id, 'bytes' => $document->size, 'sha256' => $document->sha256, 'version' => $document->version];
    }
    $delivery = OrderEmailDelivery::findOrFail($marker['delivery_id']);
    ccAssert($delivery->wc_order_id === $order->id && $delivery->status === 'prepared' && $delivery->document_mode === 'partial'
        && $delivery->send_attempts === 0 && $delivery->sync_attempts === 0 && $delivery->sync_status === 'not_requested'
        && $delivery->gmail_message_id === null && $delivery->sent_at === null, 'Capacity delivery state must remain unsent');
    ccAssert(($delivery->snapshot['marker'] ?? '') === CCP_MARKER && ($delivery->snapshot['mime_sha256'] ?? '') === $marker['mime_sha256'], 'Encrypted capacity snapshot mismatch');
    $decrypted = $delivery->mime;
    ccAssert(is_string($decrypted) && strlen($decrypted) === $marker['mime_bytes'] && hash('sha256', $decrypted) === $marker['mime_sha256'], 'Immutable encrypted MIME round-trip mismatch');
    $rawMime = DB::table('order_email_deliveries')->where('id', $delivery->id)->value('mime');
    ccAssert(is_string($rawMime) && ! str_contains($rawMime, 'FICTIONAL CLOUD CAPACITY'), 'MIME was not encrypted at rest');
    $gmailJson = json_encode(['raw' => rtrim(strtr(base64_encode($decrypted), '+/', '-_'), '=')], JSON_THROW_ON_ERROR);
    $roundTrip = json_decode($gmailJson, true, flags: JSON_THROW_ON_ERROR);
    ccAssert(hash('sha256', base64_decode(strtr($roundTrip['raw'], '-_', '+/'), true)) === $marker['mime_sha256'], 'Gmail JSON round-trip mismatch');

    $stage = 'oversize-rejection';
    $countBefore = OrderDocument::where('wc_order_id', $order->id)->count();
    $oversizeRejected = false;
    try {
        app(OrderDocumentStorage::class)->upload($order, $owner,
            [$upload('Fictional A.pdf'), $upload('Fictional B.pdf'), $upload('Fictional excess.pdf', 1)], $order->customer_email, true);
    } catch (ValidationException $exception) {
        $oversizeRejected = isset($exception->errors()['files']);
    }
    ccAssert($oversizeRejected && OrderDocument::where('wc_order_id', $order->id)->count() === $countBefore, 'Oversize aggregate was not rejected without new document rows');
    $originalNow = OrderDocument::where('wc_order_id', $original->id)->sole();
    ccAssert([$originalNow->id, $originalNow->sha256, $originalNow->version] === $originalIdentity, 'Original one-PDF fixture was changed');
    ccAssert(DB::table('jobs')->count() === $baselineJobs && DB::table('failed_jobs')->count() === $baselineFailedJobs, 'Queue counts changed during capacity probe; inspect independently');
    ccJson(['ok' => true, 'probe' => CCP_MARKER, 'checked_at' => gmdate(DATE_ATOM), 'runtime' => PHP_VERSION, 'os' => PHP_OS_FAMILY,
        'schema' => 'documents_staging', 'disk' => 'documents_private', 'bucket' => $bucket, 'order_wp_id' => 900002,
        'newly_uploaded' => $newlyUploaded, 'approved_documents' => $verified, 'aggregate_10_mib_accepted' => true,
        'over_10_mib_rejected' => true, 'delivery_id' => $delivery->id, 'immutable_mime_sha256' => $marker['mime_sha256'],
        'mime_bytes' => strlen($decrypted), 'gmail_json_bytes' => strlen($gmailJson), 'encrypted_mime_bytes' => strlen($rawMime),
        'encrypted_mime_roundtrip' => true, 'gmail_json_roundtrip' => true, 'mail_sent' => false, 'queue_jobs_dispatched' => 0,
        'send_attempts' => 0, 'sync_attempts' => 0, 'work_seconds' => round(microtime(true) - $workStarted, 3),
        'total_seconds' => round(microtime(true) - $started, 3), 'php_allocated_before_bytes' => $memoryBefore,
        'php_peak_allocated_bytes' => memory_get_peak_usage(true), 'rss_before' => $rssBefore, 'rss_after' => ccRss(),
        'settings' => ['memory_limit' => ini_get('memory_limit'), 'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize'), 'post_max_size' => ini_get('post_max_size'),
            'max_file_uploads' => ini_get('max_file_uploads'), 'mysql_max_allowed_packet_bytes' => (int) DB::selectOne('select @@max_allowed_packet as bytes')->bytes],
        'limits' => ['Actual Linux CLI, MySQL encryption and R2 work only; no HTTP upload or browser request was exercised.',
            'First run includes upload/MIME creation; repeat runs reuse stored documents and immutable MIME.',
            'Retained diagnostic buffers can exceed normal request memory; this is not a concurrency capacity or PHP-FPM configuration sign-off.',
            'No Gmail request, real mail, provider timeout, worker lifetime or completion synchronization was tested.']]);
} catch (Throwable $exception) {
    ccJson(['ok' => false, 'stage' => $stage,
        'assertion' => $exception instanceof CloudCapacityAssertion ? $exception->getMessage() : 'Operation failed; inspect private application logs without publishing secrets.',
        'exception_class' => get_class($exception)]);
    $failed = true;
} finally {
    foreach ($temporaryFiles as $path) {
        if (is_file($path)) {
            unlink($path); // Only exact temporary files created by this invocation; never stored objects.
        }
    }
}

exit($failed ? 1 : 0);
