<?php

// Approved, isolated Cloud staging only. Does not send mail, call stores or create credentials.
// Seed once; verify again after redeploy and after restoring MySQL with the same APP_KEY.
// php -d disable_functions=mail tools/validation/cloud-staging-probe.php --phase=seed --expected-app-url=https://APP.laravel.cloud --expected-bucket=BUCKET

use App\Models\GmailConnection;
use App\Models\OrderDocument;
use App\Models\OrderEmailDelivery;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Jobs\SyncDocumentCompletion;
use App\Services\DocumentValidationSafety;
use App\Services\OrderDocumentStorage;
use Dompdf\Dompdf;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

const CSP_MARKER = 'wphub-cloud-documents-v1';
const CSP_SLUG = 'fictional-cloud-documents-probe';

final class CloudProbeAssertion extends RuntimeException {}

function cpAssert(bool $condition, string $label): void
{
    if (! $condition) {
        throw new CloudProbeAssertion($label);
    }
}

function cpOutput(array $report): void
{
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
}

function cpRoute(string $path, ?User $actor, string $origin): \Symfony\Component\HttpFoundation\Response
{
    Auth::forgetGuards();
    if ($actor) {
        Auth::guard('web')->setUser($actor);
    }
    $request = Request::create($origin.$path, 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $kernel->terminate($request, $response);

    return $response;
}

$stage = 'arguments';
$temporaryPdf = null;
$started = microtime(true);
$failed = false;
try {
    cpAssert(PHP_SAPI === 'cli', 'CLI only');
    $options = getopt('', ['phase:', 'expected-app-url:', 'expected-bucket:', 'worker-label:', 'help']);
    if (array_key_exists('help', $options)) {
        cpOutput(['usage' => 'php -d disable_functions=mail tools/validation/cloud-staging-probe.php --phase=seed|verify|backup-copy|worker-enqueue|worker-verify --expected-app-url=https://APP.laravel.cloud --expected-bucket=BUCKET [--worker-label=initial|after-restart|after-redeploy|after-restore]',
            'requires' => 'Explicit APP_ENV=staging; validation capture; documents_staging MySQL schema; documents_private R2 disk; exact approved app URL and bucket. Seed requires an empty business-data schema.',
            'scope' => 'Fictional fixtures only. Real private R2 I/O and application routes; no mail, store/provider requests, deployment or resource creation. Worker phases enqueue at most one harmless real SyncDocumentCompletion per bounded label.',
            'backup_note' => 'backup-copy creates and verifies a second object prefix in the same bucket. It is NOT an independent bucket backup or a MySQL restore.']);
        exit(0);
    }
    $phase = (string) ($options['phase'] ?? '');
    $origin = rtrim((string) ($options['expected-app-url'] ?? ''), '/');
    $bucket = (string) ($options['expected-bucket'] ?? '');
    cpAssert(in_array($phase, ['seed', 'verify', 'backup-copy', 'worker-enqueue', 'worker-verify'], true), 'Explicit supported phase required');
    $workerLabel = (string) ($options['worker-label'] ?? '');
    if (str_starts_with($phase, 'worker-')) {
        cpAssert(in_array($workerLabel, ['initial', 'after-restart', 'after-redeploy', 'after-restore'], true), 'Explicit bounded worker label required');
    }
    cpAssert((bool) preg_match('#^https://[a-z0-9-]+\.laravel\.cloud$#D', $origin), 'Expected approved Cloud app URL required');
    cpAssert((bool) preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket), 'Expected approved R2 bucket required');
    // Refuse before even loading application .env/configuration unless the operator's
    // process is explicitly staging. Cached configuration is checked separately below.
    cpAssert(getenv('APP_ENV') === 'staging', 'Process APP_ENV must explicitly be staging');
    cpAssert(in_array('mail', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true), 'Run PHP with -d disable_functions=mail');

    $stage = 'bootstrap';
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $stage = 'isolation';
    cpAssert($app->environment('staging'), 'Cached application environment must be staging');
    cpAssert(rtrim((string) config('app.url'), '/') === $origin, 'Application URL does not match approved staging URL');
    cpAssert(! config('app.debug'), 'Staging APP_DEBUG must be false');
    $safety = app(DocumentValidationSafety::class);
    cpAssert($safety->enabled() && $safety->capture(), 'Validation capture mode required');
    cpAssert(config('document_delivery.validation.store_origins') === [], 'Store allowlist must be empty for storage probe');
    Http::preventStrayRequests();
    cpAssert(config('document_delivery.disk') === 'documents_private', 'Exact documents_private disk required');
    $diskConfig = config('filesystems.disks.documents_private', []);
    cpAssert(($diskConfig['driver'] ?? '') === 's3', 'Actual S3-compatible document disk required');
    cpAssert(($diskConfig['bucket'] ?? '') === $bucket, 'Document bucket does not match approved bucket');
    cpAssert(($diskConfig['visibility'] ?? 'private') !== 'public' && empty($diskConfig['url']), 'No public disk visibility or asset URL permitted');
    $endpoint = rtrim((string) ($diskConfig['endpoint'] ?? ''), '/');
    cpAssert((bool) preg_match('#^https://[a-z0-9]+(?:\.(?:eu|fedramp))?\.r2\.cloudflarestorage\.com$#D', $endpoint), 'Expected HTTPS R2 endpoint required');
    cpAssert(DB::connection()->getDriverName() === 'mysql', 'MySQL required');
    cpAssert(DB::selectOne('select database() as name')->name === 'documents_staging', 'Exact documents_staging schema required');
    // Queue bookkeeping must use the same fresh database/transaction as the fixture.
    cpAssert(config('queue.connections.documents.driver') === 'database'
        && config('queue.connections.documents.table') === 'jobs'
        && in_array(config('queue.connections.documents.connection'), [null, config('database.default')], true)
        && config('queue.failed.driver') === 'database-uuids'
        && config('queue.failed.database') === config('database.default')
        && config('queue.failed.table') === 'failed_jobs', 'Document queue and failed-job storage must use this staging database');
    $stage = 'fixture';
    $site = Website::where('slug', CSP_SLUG)->first();

    if ($phase === 'seed' && ! $site) {
        // Never import/clone production or mingle fixture creation with existing business data.
        cpAssert(! User::exists() && ! Website::exists() && ! DB::table('jobs')->exists()
            && ! WcOrder::exists() && ! DB::table('ff_submissions')->exists()
            && ! GmailConnection::exists() && ! OrderDocument::exists()
            && ! OrderEmailDelivery::exists(), 'Seed requires empty business data and no Gmail connections');
        $pdf = new Dompdf;
        $pdf->set_option('isRemoteEnabled', false);
        $pdf->loadHtml('<h1>FICTIONAL Cloud storage sample</h1><p>Not a reservation or travel document.</p><p>Private storage and restore validation only.</p>');
        $pdf->render();
        $temporaryPdf = tempnam(sys_get_temp_dir(), 'wphub-csp-');
        cpAssert(is_string($temporaryPdf) && file_put_contents($temporaryPdf, $pdf->output()) !== false, 'Temporary fictional PDF write failed');
        chmod($temporaryPdf, 0600);
        // Roll back fixture rows together if a storage/provider operation fails.
        // A private object written before a later failure may remain for inspection;
        // this probe never destroys stored objects or existing fixture records.
        $site = DB::transaction(function () use ($temporaryPdf, &$stage) {
        $owner = User::create(['name' => 'Fictional Cloud probe owner', 'email' => 'cloud-probe-owner@example.invalid', 'password' => bin2hex(random_bytes(32)), 'is_admin' => false]);
        $other = User::create(['name' => 'Fictional Cloud probe other owner', 'email' => 'cloud-probe-other@example.invalid', 'password' => bin2hex(random_bytes(32)), 'is_admin' => false]);
        $site = Website::create(['user_id' => $owner->id, 'name' => 'Fictional Cloud storage probe', 'slug' => CSP_SLUG,
            'base_url' => 'https://cloud-probe.example.invalid', 'status' => 'paused']);
        $order = WcOrder::withoutEvents(fn () => WcOrder::create(['website_id' => $site->id, 'wp_order_id' => 900001,
            'status' => 'processing', 'customer_email' => 'cloud-probe-recipient@example.invalid', 'payload' => ['staging_probe' => CSP_MARKER]]));
        $crossOrder = WcOrder::withoutEvents(fn () => WcOrder::create(['website_id' => $site->id, 'wp_order_id' => 900002,
            'status' => 'processing', 'customer_email' => 'cloud-probe-recipient@example.invalid', 'payload' => ['staging_probe' => CSP_MARKER]]));
        $stage = 'real-private-upload';
        $documents = app(OrderDocumentStorage::class)->upload($order, $owner,
            [new UploadedFile($temporaryPdf, 'Fictional Cloud storage sample.pdf', 'application/pdf', null, true)], $order->customer_email, true);
        // Required metadata foreign key only; disconnected and without any tokens/aliases.
        $gmail = GmailConnection::create(['user_id' => $owner->id, 'email' => 'cloud-probe-sender@example.invalid',
            'connection_key' => (string) Str::uuid(), 'app_fingerprint' => hash('sha256', CSP_MARKER), 'connected_at' => null, 'aliases' => []]);
        $delivery = OrderEmailDelivery::create(['user_id' => $owner->id, 'wc_order_id' => $order->id, 'website_id' => $site->id,
            'gmail_connection_id' => $gmail->id, 'connection_key' => $gmail->connection_key, 'fingerprint' => hash('sha256', CSP_MARKER),
            'snapshot' => ['marker' => CSP_MARKER, 'documents' => $documents, 'other_user_id' => $other->id, 'cross_order_id' => $crossOrder->id],
            'mime' => 'FICTIONAL STORAGE PROBE — no message was sent', 'status' => 'prepared', 'send_attempts' => 0,
            'expires_at' => now()->addDays(2), 'workflow' => true, 'document_mode' => 'partial', 'sync_status' => 'not_requested']);
        WcOrder::withoutEvents(fn () => $order->update(['payload' => ['staging_probe' => CSP_MARKER, 'delivery_id' => $delivery->id]]));
        return $site;
        });
    }

    $stage = 'verify-records';
    cpAssert($site !== null && $site->status === 'paused' && $site->base_url === 'https://cloud-probe.example.invalid', 'Recognized paused fictional fixture required');
    $order = WcOrder::where('website_id', $site->id)->where('wp_order_id', 900001)->firstOrFail();
    cpAssert(($order->payload['staging_probe'] ?? '') === CSP_MARKER, 'Fixture marker missing');
    $delivery = OrderEmailDelivery::whereKey($order->payload['delivery_id'] ?? '')->firstOrFail();
    $snapshot = $delivery->snapshot;
    cpAssert(($snapshot['marker'] ?? '') === CSP_MARKER && $delivery->wc_order_id === $order->id, 'Encrypted snapshot or order relationship mismatch');
    cpAssert($delivery->status === 'prepared' && $delivery->send_attempts === 0 && $delivery->sync_status === 'not_requested' && $delivery->sync_attempts === 0
        && $delivery->gmail_message_id === null && $delivery->sent_at === null, 'Storage probe must have no sends or completion requests');
    $ciphertext = DB::table('order_email_deliveries')->where('id', $delivery->id)->value('snapshot');
    cpAssert(is_string($ciphertext) && ! str_contains($ciphertext, CSP_MARKER), 'Snapshot plaintext leaked at rest');
    cpAssert($delivery->mime === 'FICTIONAL STORAGE PROBE — no message was sent', 'Encrypted MIME read mismatch');
    $gmail = $delivery->connection;
    cpAssert($gmail && $gmail->access_token === null && $gmail->refresh_token === null && $gmail->connected_at === null, 'Fixture Gmail metadata must remain disconnected');
    $document = OrderDocument::where('wc_order_id', $order->id)->sole();
    cpAssert($document->disk === 'documents_private' && $document->website_id === $site->id && $document->approved_at !== null
        && $document->version === 1 && $document->sha256 === ($snapshot['documents'][0]['sha256'] ?? null), 'Document approval/version/hash metadata mismatch');
    $worker = null;
    $dispatched = 0;
    if (str_starts_with($phase, 'worker-')) {
        $stage = $phase;
        cpAssert($delivery->document_mode === 'partial' && $order->status === 'processing' && $delivery->sync_claimed_at === null
            && $delivery->synced_at === null && $delivery->next_sync_at === null, 'Worker probe must remain a prepared partial, unclaimed fixture');
        if ($phase === 'worker-enqueue') {
            DB::transaction(function () use ($order, $delivery, $workerLabel, &$dispatched) {
                $locked = WcOrder::whereKey($order->id)->lockForUpdate()->firstOrFail();
                $payload = $locked->payload;
                if (isset($payload['worker_checks'][$workerLabel])) {
                    return; // Idempotent; never silently enqueue the same label twice.
                }
                $baseline = (int) (DB::table('failed_jobs')->max('id') ?? 0);
                $job = (new SyncDocumentCompletion($delivery->id))->beforeCommit();
                // Insert within this transaction so a managed worker cannot consume it
                // before its exact ID/UUID and failure baseline have been saved.
                $jobId = Queue::connection('documents')->push($job, '', 'documents');
                cpAssert(is_numeric($jobId), 'Real database queue insert did not return a job ID');
                $row = DB::table('jobs')->where('id', $jobId)->where('queue', 'documents')->first();
                $jobPayload = $row ? json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR) : [];
                cpAssert(($jobPayload['displayName'] ?? '') === SyncDocumentCompletion::class
                    && Str::isUuid($jobPayload['uuid'] ?? ''), 'Unexpected database queue job payload');
                $payload['worker_checks'][$workerLabel] = ['job_id' => (int) $jobId, 'job_uuid' => $jobPayload['uuid'],
                    'failed_jobs_max_id' => $baseline, 'failed_jobs_count' => DB::table('failed_jobs')->count(),
                    'enqueued_at' => gmdate(DATE_ATOM), 'send_attempts' => 0, 'sync_attempts' => 0];
                WcOrder::withoutEvents(fn () => $locked->update(['payload' => $payload]));
                $dispatched = 1;
            });
        }
        $marker = $order->fresh()->payload['worker_checks'][$workerLabel] ?? null;
        cpAssert(is_array($marker) && isset($marker['job_id'], $marker['job_uuid'], $marker['failed_jobs_max_id']), 'Enqueue this worker label before verifying it');
        $remaining = DB::table('jobs')->where('id', $marker['job_id'])->exists();
        $newFailures = DB::table('failed_jobs')->where('id', '>', $marker['failed_jobs_max_id'])->count();
        $matchingFailures = DB::table('failed_jobs')->where('uuid', $marker['job_uuid'])->count();
        $current = $delivery->fresh();
        cpAssert($current->status === 'prepared' && $current->document_mode === 'partial' && $current->send_attempts === 0
            && $current->sync_attempts === 0 && $current->sync_status === 'not_requested' && $current->sync_claimed_at === null
            && $current->sent_at === null && $current->synced_at === null && $current->next_sync_at === null
            && $order->fresh()->status === 'processing', 'Worker changed a prepared partial fixture');
        if ($phase === 'worker-verify') {
            cpAssert(! $remaining, 'Worker job has not been consumed; retry verification after the managed worker runs');
            cpAssert($newFailures === 0 && $matchingFailures === 0, 'Worker recorded a new failed job');
        }
        $worker = ['label' => $workerLabel, 'job_id' => $marker['job_id'], 'job_uuid' => $marker['job_uuid'],
            'consumed' => ! $remaining, 'new_failed_jobs' => $newFailures, 'matching_failed_jobs' => $matchingFailures,
            'send_attempts' => $current->send_attempts, 'sync_attempts' => $current->sync_attempts,
            'scope' => 'Real job consumption only. Prepared partial delivery returns unclaimed; no provider/store call or fulfillment success is claimed.'];
    }
    $stage = 'real-private-read';
    $bytes = app(OrderDocumentStorage::class)->bytes($document);
    $disk = Storage::disk('documents_private');
    $copies = [];
    if ($phase === 'backup-copy') {
        $stage = 'backup-copy';
        $manifest = $order->payload['object_copy'] ?? null;
        if (! $manifest) {
            $copyKey = 'staging-probe-backup/'.Str::uuid().'/'.$document->id.'.pdf';
            cpAssert($disk->put($copyKey, $bytes, ['visibility' => 'private']), 'Private backup-prefix copy write failed');
            $manifest = ['key' => $copyKey, 'sha256' => $document->sha256, 'bytes' => $document->size];
            $payload = $order->payload;
            $payload['object_copy'] = $manifest;
            WcOrder::withoutEvents(fn () => $order->update(['payload' => $payload]));
        }
    }
    if ($manifest = ($order->fresh()->payload['object_copy'] ?? null)) {
        cpAssert(str_starts_with($manifest['key'] ?? '', 'staging-probe-backup/'), 'Unexpected object-copy prefix');
        $copyBytes = $disk->get($manifest['key']);
        cpAssert(is_string($copyBytes) && strlen($copyBytes) === $document->size && hash('sha256', $copyBytes) === $document->sha256, 'Private backup-prefix copy read/hash mismatch');
        $copies[] = ['sha256' => $document->sha256, 'bytes' => strlen($copyBytes), 'readback' => true, 'independent_backup' => false];
    }

    $stage = 'ownership-routes';
    $owner = User::findOrFail($site->user_id);
    $other = User::findOrFail($snapshot['other_user_id']);
    cpAssert(! $owner->is_admin && ! $other->is_admin && $owner->email === 'cloud-probe-owner@example.invalid'
        && $other->email === 'cloud-probe-other@example.invalid', 'Fictional non-admin owners required');
    $path = '/orders/'.$order->id.'/document-deliveries/documents/'.$document->id;
    cpAssert(cpRoute($path, null, $origin)->getStatusCode() === 401, 'Anonymous route did not return 401');
    cpAssert(cpRoute($path, $other, $origin)->getStatusCode() === 403, 'Other owner route did not return 403');
    $response = cpRoute($path, $owner, $origin);
    cpAssert($response->getStatusCode() === 200 && hash('sha256', $response->getContent()) === $document->sha256, 'Owner route did not return original PDF bytes');
    $cache = $response->headers->get('Cache-Control', '');
    cpAssert(str_contains($cache, 'private') && str_contains($cache, 'no-store'), 'Private no-store response required');
    cpAssert(cpRoute('/orders/'.$snapshot['cross_order_id'].'/document-deliveries/documents/'.$document->id, $owner, $origin)->getStatusCode() === 404, 'Cross-order route did not return 404');
    $publicStatus = cpRoute('/storage/'.$document->path, null, $origin)->getStatusCode();
    cpAssert(in_array($publicStatus, [403, 404]), 'Guessed public path was accessible');

    $stage = 'anonymous-r2';
    // Direct unsigned GET only to the already verified bucket's R2 S3 origin.
    // No AWS Authorization or app cookies. Never follow a redirect to another host.
    $anonymousUrl = $disk->getClient()->getObjectUrl($bucket, $document->path);
    $anonymousHost = parse_url($anonymousUrl, PHP_URL_HOST);
    $endpointHost = parse_url($endpoint, PHP_URL_HOST);
    cpAssert(parse_url($anonymousUrl, PHP_URL_SCHEME) === 'https' && in_array($anonymousHost, [$endpointHost, $bucket.'.'.$endpointHost], true)
        && parse_url($anonymousUrl, PHP_URL_QUERY) === null && parse_url($anonymousUrl, PHP_URL_USER) === null, 'Unexpected anonymous R2 target');
    $anonymous = (new \GuzzleHttp\Client)->get($anonymousUrl, ['allow_redirects' => false, 'http_errors' => false, 'timeout' => 15, 'connect_timeout' => 5, 'verify' => true]);
    $anonymousStatus = $anonymous->getStatusCode();
    cpAssert(in_array($anonymousStatus, [401, 403, 404], true), 'Unsigned R2 object request was not denied');
    cpOutput(['ok' => true, 'probe' => CSP_MARKER, 'phase' => $phase, 'checked_at' => gmdate(DATE_ATOM), 'runtime' => PHP_VERSION,
        'environment' => 'staging', 'schema' => 'documents_staging', 'disk' => 'documents_private', 'bucket' => $bucket,
        'document_id' => $document->id, 'sha256' => $document->sha256, 'bytes' => $document->size, 'approved_version' => $document->version,
        'approved_at' => $document->approved_at->toIso8601String(), 'delivery_id' => $delivery->id,
        'snapshot_ciphertext_sha256' => hash('sha256', $ciphertext), 'encrypted_snapshot_read' => true, 'encrypted_mime_read' => true,
        'send_attempts' => 0, 'mail_sent' => false, 'queue_jobs_dispatched' => $dispatched, 'worker' => $worker, 'owner_http' => 200, 'anonymous_http' => 401,
        'other_owner_http' => 403, 'cross_order_http' => 404, 'public_path_http' => $publicStatus, 'cache_control' => $cache,
        'unsigned_r2_http' => $anonymousStatus, 'object_copies' => $copies, 'elapsed_seconds' => round(microtime(true) - $started, 3),
        'peak_allocated_bytes' => memory_get_peak_usage(true),
        'limits' => ['Application routes exercised in-process; edge HTTP access is a separate check.',
            'R2 unsigned S3 access denial does not replace inspecting bucket-wide public/CDN visibility.',
            'Compare document ID, hash, approval version and ciphertext hash across separate redeploy/restore runs.',
            'Worker lifecycle, capacity and actual platform backup/restore are not established by one probe run.']]);
} catch (Throwable $exception) {
    // Provider/database exception text can contain signed URLs or credentials. Keep output safe.
    cpOutput(['ok' => false, 'stage' => $stage, 'assertion' => $exception instanceof CloudProbeAssertion ? $exception->getMessage() : 'Operation failed; inspect private application logs without publishing secrets.',
        'exception_class' => get_class($exception)]);
    $failed = true;
} finally {
    if (is_string($temporaryPdf) && is_file($temporaryPdf)) {
        unlink($temporaryPdf); // Exact temporary file created by this process only.
    }
}

exit($failed ? 1 : 0);
