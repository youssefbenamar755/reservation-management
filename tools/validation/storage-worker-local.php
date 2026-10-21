<?php

// Local-only lifecycle/restore/capacity probe. Never loads repository .env or production state.
// php -d disable_functions=mail tools/validation/storage-worker-local.php --output=C:/private/existing/path
// This deliberately uses real SQLite/local Flysystem/Laravel workers, but a simulated store gateway.

require dirname(__DIR__, 2).'/vendor/autoload.php';

use App\Jobs\SyncDocumentCompletion;
use App\Models\GmailConnection;
use App\Models\OrderDocument;
use App\Models\OrderEmailDelivery;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Services\DocumentStoreGateway;
use App\Services\DocumentValidationSafety;
use App\Services\OrderDocumentStorage;
use Dompdf\Dompdf;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Mime\Email;

// This Windows sandbox incorrectly reports is_readable() false for freshly written files.
// Keep actual file reads and fileinfo MIME detection; bypass only that metadata predicate.
class StorageWorkerReadableUpload extends \Illuminate\Http\UploadedFile
{
    public function getMimeType(): ?string
    {
        $bytes = file_get_contents($this->getPathname());
        swAssert(is_string($bytes), 'Fixture PDF could not be read');
        return (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: null;
    }
}

function swAssert(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException($label);
    }
}

function swJson(string $path, mixed $data): void
{
    swAssert(file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n") !== false, 'Evidence write failed');
}

function swBoundaryPdf(int $target): string
{
    // A real one-page PDF with a large harmless content-stream comment, not bytes
    // inserted around startxref/EOF (which would invalidate strict parsers).
    $padding = $target - 1000;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        $stream = "BT /F1 18 Tf 40 780 Td (FICTIONAL local storage/restore sample) Tj 0 -30 Td /F1 12 Tf (Not a reservation or travel document.) Tj ET\n%".str_repeat(' ', $padding)."\n";
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
        foreach (array_slice($offsets, 1) as $offset) $pdf .= sprintf('%010d 00000 n ', $offset)."\n";
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n$xref\n%%EOF\n";
        if (strlen($pdf) === $target) return $pdf;
        $padding += $target - strlen($pdf);
    }
    throw new RuntimeException('Boundary PDF did not reach exact size');
}

function swFolders(string $root): void
{
    swAssert(mkdir($root, 0700), 'Fresh isolated directory required');
    foreach (['cache', 'storage/framework/views', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/logs', 'storage/app/private', 'inputs'] as $part) {
        swAssert(mkdir($root.'/'.$part, 0700, true), 'Isolated subdirectory failed');
    }
    foreach (['packages', 'services'] as $metadata) {
        $source = dirname(__DIR__, 2).'/bootstrap/cache/'.$metadata.'.php';
        if (is_file($source)) {
            $classes = require $source;
            swAssert(is_array($classes), 'Unexpected provider discovery metadata');
            file_put_contents($root.'/cache/'.$metadata.'.php', '<?php return '.var_export($classes, true).';');
        }
    }
}

function swBootstrap(string $root, bool $wrongKey = false): array
{
    $root = realpath($root);
    swAssert($root !== false && is_file($root.'/state.private.json'), 'Fresh storage-worker fixture required');
    $state = json_decode(file_get_contents($root.'/state.private.json'), true, flags: JSON_THROW_ON_ERROR);
    swAssert(($state['format'] ?? '') === 'wphub-storage-worker-local-v1', 'Unrecognized fixture');
    $repo = dirname(__DIR__, 2);
    foreach (glob($repo.'/config/*.php') as $file) {
        preg_match_all('/\benv\([\'\"]([A-Z][A-Z0-9_]*)[\'\"]/', file_get_contents($file), $matches);
        foreach ($matches[1] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
    $environment = [
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => $wrongKey ? 'base64:'.base64_encode(random_bytes(32)) : $state['key'],
        'APP_PREVIOUS_KEYS' => '', 'APP_URL' => 'http://localhost', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $root.'/fixture.sqlite', 'DB_URL' => '',
        'APP_CONFIG_CACHE' => $root.'/absent-config.php', 'APP_ROUTES_CACHE' => $root.'/absent-routes.php', 'APP_EVENTS_CACHE' => $root.'/absent-events.php',
        'APP_SERVICES_CACHE' => $root.'/cache/services.php', 'APP_PACKAGES_CACHE' => $root.'/cache/packages.php',
        'CACHE_STORE' => 'database', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'BROADCAST_CONNECTION' => 'null', 'BROADCAST_DRIVER' => 'null', 'FILESYSTEM_DISK' => 'local', 'WPHUB_DOCUMENT_DISK' => 'local',
        'WPHUB_VALIDATION_MODE' => 'true', 'WPHUB_VALIDATION_MAIL_TRANSPORT' => 'capture',
        'WPHUB_VALIDATION_RECIPIENTS' => 'fixture-recipient@example.invalid', 'WPHUB_VALIDATION_STORE_ORIGINS' => '',
        'NIGHTWATCH_ENABLED' => 'false', 'NIGHTWATCH_TOKEN' => '', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false',
        'LOG_CHANNEL' => 'single', 'LOG_LEVEL' => 'warning', 'AWS_ACCESS_KEY_ID' => '', 'AWS_SECRET_ACCESS_KEY' => '', 'AWS_BUCKET' => '',
        'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '', 'PUSHER_APP_ID' => '', 'PUSHER_APP_KEY' => '', 'PUSHER_APP_SECRET' => '',
    ];
    foreach ($environment as $key => $value) {
        putenv($key.'='.$value);
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
    foreach (['absent.env', 'absent-config.php', 'absent-routes.php', 'absent-events.php'] as $path) {
        swAssert(! file_exists($root.'/'.$path), 'Unexpected cached configuration in fixture');
    }
    $app = require $repo.'/bootstrap/app.php';
    if (preg_match('/^[A-Za-z]:/', $root, $drive)) {
        $app->addAbsoluteCachePathPrefix($drive[0]);
    }
    $app->useEnvironmentPath($root)->loadEnvironmentFrom('absent.env');
    $app->useStoragePath($root.'/storage');
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();
    config(['inertia.ssr.enabled' => false, 'filesystems.disks.local.serve' => false]);
    Http::preventStrayRequests();
    swAssert(app(DocumentValidationSafety::class)->capture() && config('document_delivery.validation.store_origins') === [], 'Transport guard not active');
    swAssert(config('database.connections.sqlite.database') === $root.'/fixture.sqlite', 'Unexpected database');
    swAssert(str_replace('\\', '/', config('filesystems.disks.local.root')) === str_replace('\\', '/', $root.'/storage/app/private'), 'Unexpected private root');
    swAssert(config('queue.default') === 'sync' && config('queue.connections.documents.driver') === 'database', 'Dedicated queue configuration changed');
    return [$app, $kernel, $root, $state];
}

function swChild(string $root, string $phase, bool $background = false): mixed
{
    $command = [PHP_BINARY, '-d', 'disable_functions=mail', '-d', 'memory_limit=512M', __FILE__, '--fixture='.$root, '--phase='.$phase];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $root.'/'.$phase.'.private.log', 'a'], 2 => ['file', $root.'/'.$phase.'.private.log', 'a']], $pipes, dirname(__DIR__, 2));
    swAssert(is_resource($process), 'Subprocess could not start');
    fclose($pipes[0]);
    if ($background) {
        return $process;
    }
    swAssert(proc_close($process) === 0, 'Phase failed: '.$phase.' (see private phase log)');
    return null;
}

function swCopyTree(string $source, string $destination): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST) as $file) {
        $target = $destination.substr($file->getPathname(), strlen($source));
        if ($file->isDir()) {
            if (! is_dir($target)) swAssert(mkdir($target, 0700, true), 'Restore directory failed');
        } else {
            swAssert(copy($file->getPathname(), $target), 'Restore copy failed');
        }
    }
}

$options = getopt('', ['output:', 'fixture:', 'phase:']);
$started = microtime(true);
try {
    if (isset($options['output'])) {
        $parent = realpath($options['output']);
        swAssert($parent !== false && is_dir($parent), 'Existing private output parent required');
        foreach ([dirname(__DIR__, 2).'/public', dirname(__DIR__, 2).'/storage'] as $public) {
            swAssert(! str_starts_with(strtolower(str_replace('\\', '/', $parent)).'/', strtolower(str_replace('\\', '/', $public)).'/'), 'Output cannot be inside application public/storage');
        }
        $root = $parent.'/run-'.bin2hex(random_bytes(8));
        swFolders($root);
        file_put_contents($root.'/fixture.sqlite', '');
        swJson($root.'/state.private.json', ['format' => 'wphub-storage-worker-local-v1', 'key' => 'base64:'.base64_encode(random_bytes(32))]);
        swChild($root, 'seed');
        swChild($root, 'read');
        $worker = swChild($root, 'worker-fail', true);
        $deadline = microtime(true) + 20;
        while (! file_exists($root.'/gateway-called.marker') && microtime(true) < $deadline) usleep(100000);
        swAssert(file_exists($root.'/gateway-called.marker'), 'Worker did not process fixture job');
        swChild($root, 'restart');
        $deadline = microtime(true) + 20;
        do {
            $status = proc_get_status($worker);
            if (! $status['running']) break;
            usleep(100000);
        } while (microtime(true) < $deadline);
        swAssert(! $status['running'] && $status['exitcode'] === 0, 'Worker did not exit cleanly after restart signal');
        proc_close($worker);
        swChild($root, 'inspect-failure');
        swChild($root, 'worker-success');
        swChild($root, 'recovery');
        swChild($root, 'capacity');
        swChild($root, 's3-wire');
        swChild($root, 'backup');
        $restore = $parent.'/restore-'.bin2hex(random_bytes(8));
        swFolders($restore);
        swAssert(copy($root.'/backup/fixture.sqlite', $restore.'/fixture.sqlite'), 'Restore database failed');
        swAssert(copy($root.'/state.private.json', $restore.'/state.private.json'), 'Restore key failed');
        swCopyTree($root.'/backup/private', $restore.'/storage/app/private');
        swChild($restore, 'read');
        swChild($restore, 'wrong-key');
        $report = ['result' => 'passed', 'scope' => 'Local real SQLite, local Flysystem, real Laravel queue worker and encrypted restore; simulated store gateway only.',
            'recorded_at' => gmdate(DATE_ATOM), 'run_directory' => $root, 'restored_directory' => $restore,
            'local_process_restart_hash_verified' => true, 'local_worker_restart_verified' => true, 'local_consistent_backup_restore_verified' => true,
            'cloud_s3_r2_acl_verified' => false, 'cloud_redeploy_persistence_verified' => false, 'cloud_worker_grace_timeout_verified' => false,
            'production_capacity_verified' => false, 'external_http_transmitted' => 0, 'real_emails_sent' => 0,
            'windows_pcntl_timeout_supported' => function_exists('pcntl_signal'), 'duration_seconds' => round(microtime(true) - $started, 3), 'phases' => []];
        foreach (['seed', 'read', 'worker-fail', 'restart', 'inspect-failure', 'worker-success', 'recovery', 'capacity', 's3-wire', 'backup'] as $phase) {
            $report['phases'][$phase] = json_decode(file_get_contents($root.'/'.$phase.'.json'), true, flags: JSON_THROW_ON_ERROR);
        }
        $report['phases']['restored-read'] = json_decode(file_get_contents($restore.'/read.json'), true, flags: JSON_THROW_ON_ERROR);
        swAssert($report['phases']['restored-read']['delivery_sync_status'] === 'completed', 'Restored delivery history missing');
        swAssert($report['phases']['restored-read']['documents_verified'] === count($report['phases']['backup']['documents']), 'Not all restored documents verified');
        $report['phases']['wrong-key'] = json_decode(file_get_contents($restore.'/wrong-key.json'), true, flags: JSON_THROW_ON_ERROR);
        swJson($parent.'/results.json', $report);
        echo json_encode(['result' => 'passed', 'report' => $parent.'/results.json', 'duration_seconds' => $report['duration_seconds']])."\n";
        exit;
    }
    $phase = $options['phase'] ?? '';
    [$app, $kernel, $root, $state] = swBootstrap((string) ($options['fixture'] ?? ''), $phase === 'wrong-key');
    $checks = [];
    if ($phase === 'seed') {
        swAssert($kernel->call('migrate', ['--force' => true]) === 0, 'Fresh migrations failed');
        $user = User::create(['name' => 'Fictional owner', 'email' => 'owner@example.invalid', 'password' => bin2hex(random_bytes(24)), 'is_admin' => false]);
        $stranger = User::create(['name' => 'Fictional other owner', 'email' => 'other@example.invalid', 'password' => bin2hex(random_bytes(24)), 'is_admin' => false]);
        $site = Website::create(['user_id' => $user->id, 'name' => 'Fictional storage test', 'slug' => 'storage-worker-test', 'base_url' => 'https://storage.example.invalid', 'status' => 'paused']);
        $order = WcOrder::withoutEvents(fn () => WcOrder::create(['website_id' => $site->id, 'wp_order_id' => 900001, 'status' => 'processing', 'customer_email' => 'fixture-recipient@example.invalid', 'payload' => ['fictional' => true]]));
        $other = WcOrder::withoutEvents(fn () => WcOrder::create(['website_id' => $site->id, 'wp_order_id' => 900002, 'status' => 'processing', 'customer_email' => 'fixture-recipient@example.invalid', 'payload' => ['fictional' => true]]));
        $pdf = new Dompdf;
        $pdf->set_option('isRemoteEnabled', false);
        $pdf->loadHtml('<h1>FICTIONAL local storage/restore sample</h1><p>Not a reservation or travel document.</p>');
        $pdf->render();
        file_put_contents($root.'/inputs/sample.pdf', $pdf->output());
        $file = new StorageWorkerReadableUpload($root.'/inputs/sample.pdf', 'Fictional sample.pdf', 'application/pdf', null, true);
        $documents = app(OrderDocumentStorage::class)->upload($order, $user, [$file], $order->customer_email, true);
        $gmail = GmailConnection::create(['user_id' => $user->id, 'email' => 'sender@example.invalid', 'connection_key' => (string) Str::uuid(), 'app_fingerprint' => hash('sha256', 'fictional-not-connected'), 'access_token' => 'FICTIONAL NOT A TOKEN', 'refresh_token' => 'FICTIONAL NOT A TOKEN', 'connected_at' => now()]);
        $delivery = OrderEmailDelivery::create(['user_id' => $user->id, 'wc_order_id' => $order->id, 'website_id' => $site->id, 'gmail_connection_id' => $gmail->id,
            'connection_key' => $gmail->connection_key, 'fingerprint' => hash('sha256', 'fictional'), 'snapshot' => ['marker' => 'FICTIONAL-ENCRYPTED-RESTORE-CHECK', 'documents' => $documents],
            'status' => 'sent', 'gmail_message_id' => 'capture_fixture_no_email_sent', 'send_attempts' => 1, 'sent_at' => now(), 'expires_at' => now()->addHour(),
            'workflow' => true, 'document_mode' => 'complete', 'sync_status' => 'pending', 'sync_attempts' => 0]);
        SyncDocumentCompletion::dispatch($delivery->id);
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'reserved_at' => null, 'available_at' => time(), 'created_at' => time()]);
        swAssert(DB::table('jobs')->where('queue', 'documents')->count() === 1, 'Document job not routed to dedicated queue');
        swAssert(! str_contains(DB::table('order_email_deliveries')->value('snapshot'), 'FICTIONAL-ENCRYPTED-RESTORE-CHECK'), 'Snapshot was not encrypted at rest');
        $checks = ['approved_documents' => $documents, 'snapshot_encrypted_at_rest' => true, 'dedicated_documents_job' => true, 'default_queue_canary' => 1, 'fictional_records_only' => true];
    } elseif ($phase === 'read') {
        $document = OrderDocument::orderBy('version')->firstOrFail();
        $order = WcOrder::findOrFail($document->wc_order_id);
        $bytes = app(OrderDocumentStorage::class)->bytes($document);
        swAssert(hash('sha256', $bytes) === $document->sha256, 'Fresh-process PDF hash mismatch');
        swAssert(OrderEmailDelivery::firstOrFail()->snapshot['marker'] === 'FICTIONAL-ENCRYPTED-RESTORE-CHECK', 'Snapshot decrypt/read mismatch');
        $http = app(\Illuminate\Contracts\Http\Kernel::class);
        $url = '/orders/'.$order->id.'/document-deliveries/documents/'.$document->id;
        $request = \Illuminate\Http\Request::create($url, 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
        Auth::forgetGuards();
        $response = $http->handle($request);
        swAssert($response->getStatusCode() === 401, 'Anonymous document route not rejected');
        Auth::guard('web')->setUser(User::where('email', 'other@example.invalid')->firstOrFail());
        $response = $http->handle(\Illuminate\Http\Request::create($url, 'GET', server: ['HTTP_ACCEPT' => 'application/json']));
        swAssert($response->getStatusCode() === 403, 'Other owner was not rejected');
        Auth::guard('web')->setUser(User::findOrFail($document->approved_by));
        $response = $http->handle(\Illuminate\Http\Request::create($url, 'GET', server: ['HTTP_ACCEPT' => 'application/json']));
        swAssert($response->getStatusCode() === 200 && hash('sha256', $response->getContent()) === $document->sha256, 'Authorized download mismatch');
        $otherOrder = WcOrder::where('id', '!=', $order->id)->firstOrFail();
        $cross = $http->handle(\Illuminate\Http\Request::create('/orders/'.$otherOrder->id.'/document-deliveries/documents/'.$document->id, 'GET', server: ['HTTP_ACCEPT' => 'application/json']));
        swAssert($cross->getStatusCode() === 404, 'Cross-order document route not rejected');
        $public = $http->handle(\Illuminate\Http\Request::create('/storage/'.$document->path, 'GET', server: ['HTTP_ACCEPT' => 'application/json']));
        swAssert(in_array($public->getStatusCode(), [403, 404]), 'Guessed public path did not fail');
        $verified = [];
        foreach (OrderDocument::all() as $item) {
            swAssert(hash('sha256', app(OrderDocumentStorage::class)->bytes($item)) === $item->sha256 && $item->approved_at !== null, 'Restored document integrity or approval mismatch');
            $verified[] = ['id' => $item->id, 'sha256' => $item->sha256, 'version' => $item->version];
        }
        $checks = ['sha256' => $document->sha256, 'size' => $document->size, 'approved_version' => $document->version, 'approved_at_present' => $document->approved_at !== null,
            'documents_verified' => count($verified), 'document_hashes_versions' => $verified, 'delivery_sync_status' => OrderEmailDelivery::firstOrFail()->sync_status,
            'delivery_send_attempts' => OrderEmailDelivery::firstOrFail()->send_attempts,
            'fresh_process_pid' => getmypid(), 'anonymous_http' => 401, 'other_owner_http' => 403, 'owner_http' => 200, 'cross_order_http' => 404,
            'guessed_public_path_http' => $public->getStatusCode(), 'cache_control' => $response->headers->get('Cache-Control'), 'encrypted_snapshot_read' => true];
    } elseif (in_array($phase, ['worker-fail', 'worker-success', 'recovery'])) {
        $gateway = new class($root, $phase) extends DocumentStoreGateway {
            public function __construct(private string $root, private string $phase) {}
            public function complete(WcOrder $order, OrderEmailDelivery $delivery): array
            {
                file_put_contents($this->root.'/gateway-calls.jsonl', json_encode(['phase' => $this->phase, 'delivery' => $delivery->id, 'simulated' => true])."\n", FILE_APPEND);
                file_put_contents($this->root.'/gateway-called.marker', 'simulated store gateway called');
                if ($this->phase === 'worker-fail') throw new RuntimeException('Simulated unavailable store; no HTTP');
                return ['status' => 'completed', 'simulated' => true];
            }
        };
        app()->instance(DocumentStoreGateway::class, $gateway);
        if ($phase === 'recovery') {
            $delivery = OrderEmailDelivery::firstOrFail();
            $delivery->update(['sync_status' => 'syncing', 'sync_claimed_at' => now()->subMinutes(6)]);
            swAssert($kernel->call('documents:retry-completion') === 0, 'Stale-claim recovery failed');
            swAssert($delivery->fresh()->sync_status === 'completed' && $delivery->fresh()->sync_attempts === 3, 'Stale sync was not recovered');
            $before = count(file($root.'/gateway-calls.jsonl'));
            app(\App\Services\DocumentCompletionSync::class)->sync($delivery->id);
            swAssert(count(file($root.'/gateway-calls.jsonl')) === $before, 'Completed sync was repeated');
            $checks = ['simulated_stale_claim_recovered' => true, 'completed_sync_idempotent' => true, 'send_attempts_unchanged' => $delivery->fresh()->send_attempts === 1];
        } else {
            $arguments = ['connection' => 'documents', '--queue' => 'documents', '--sleep' => 1, '--tries' => 1, '--timeout' => 90, '--max-time' => 30];
            if ($phase === 'worker-success') $arguments['--stop-when-empty'] = true;
            swAssert($kernel->call('queue:work', $arguments) === 0, 'Worker exit was not successful');
            $delivery = OrderEmailDelivery::firstOrFail();
            swAssert($delivery->sync_status === ($phase === 'worker-fail' ? 'pending' : 'completed'), 'Unexpected sync result');
            swAssert(DB::table('jobs')->where('queue', 'default')->count() === 1, 'Unrelated default queue was drained');
            swAssert(DB::table('jobs')->where('queue', 'documents')->count() === 0, 'Document job not drained');
            $checks = ['sync_status' => $delivery->sync_status, 'sync_attempts' => $delivery->sync_attempts, 'send_attempts' => $delivery->send_attempts,
                'default_queue_untouched' => true, 'documents_queue_empty' => true, 'worker_pid' => getmypid(), 'simulated_store_gateway' => true];
        }
    } elseif ($phase === 'restart') {
        swAssert($kernel->call('queue:restart') === 0, 'Queue restart signal failed');
        $checks = ['real_laravel_restart_signal_written' => true];
    } elseif ($phase === 'inspect-failure') {
        $delivery = OrderEmailDelivery::firstOrFail();
        swAssert($delivery->sync_status === 'pending' && $delivery->next_sync_at->gt(now()) && $delivery->send_attempts === 1, 'Failure did not preserve pending sync-only state');
        SyncDocumentCompletion::dispatch($delivery->id);
        $checks = ['future_sync_retry_recorded' => true, 'email_not_reset' => true, 'explicit_sync_only_job_dispatched' => true];
    } elseif ($phase === 'capacity') {
        $bytes = swBoundaryPdf(5242880);
        swAssert(strlen($bytes) === 5242880, 'Boundary PDF wrong size');
        file_put_contents($root.'/inputs/maximum.pdf', $bytes);
        $order = WcOrder::firstOrFail();
        $user = User::findOrFail($order->website->user_id);
        $make = fn ($name) => new StorageWorkerReadableUpload($root.'/inputs/maximum.pdf', $name, 'application/pdf', null, true);
        $memoryBefore = memory_get_usage(true);
        $workStart = microtime(true);
        $docs = app(OrderDocumentStorage::class)->upload($order, $user, [$make('Fictional A.pdf'), $make('Fictional B.pdf')], $order->customer_email, true);
        $mime = (new Email)->from('sender@example.invalid')->to('fixture-recipient@example.invalid')->subject('[LOCAL CAPACITY] FICTIONAL')
            ->text('Fictional local benchmark only. No email sent.')->html('<p>Fictional local benchmark only.</p>');
        foreach ($docs as $summary) {
            $document = OrderDocument::findOrFail($summary['id']);
            $mime->attach(app(OrderDocumentStorage::class)->bytes($document), $document->name, 'application/pdf');
        }
        $serialized = $mime->toString();
        $delivery = OrderEmailDelivery::firstOrFail();
        $delivery->update(['mime' => $serialized]);
        $decrypted = $delivery->fresh()->mime;
        swAssert(hash('sha256', $decrypted) === hash('sha256', $serialized), 'Large encrypted MIME round-trip mismatch');
        $gmailJson = json_encode(['raw' => rtrim(strtr(base64_encode($decrypted), '+/', '-_'), '=')], JSON_THROW_ON_ERROR);
        $delivery->update(['mime' => null]);
        $elapsed = microtime(true) - $workStart;
        try {
            app(OrderDocumentStorage::class)->upload($order, $user, [$make('A.pdf'), $make('B.pdf'), new StorageWorkerReadableUpload($root.'/inputs/sample.pdf', 'C.pdf', 'application/pdf', null, true)], $order->customer_email, true);
            throw new RuntimeException('Aggregate upload limit did not reject');
        } catch (\Illuminate\Validation\ValidationException) {}
        $document = OrderDocument::firstOrFail();
        $original = Storage::disk($document->disk)->get($document->path);
        Storage::disk($document->disk)->put($document->path, '%PDF-tampered');
        try {
            app(OrderDocumentStorage::class)->bytes($document);
            throw new RuntimeException('Tampered PDF was accepted');
        } catch (\Illuminate\Validation\ValidationException) {
        } finally {
            Storage::disk($document->disk)->put($document->path, $original);
        }
        $checks = ['aggregate_10_mib_accepted' => true, 'over_10_mib_rejected' => true, 'large_mime_encrypted_roundtrip' => true, 'tampered_document_rejected' => true, 'original_restored_and_hash_valid' => hash('sha256', app(OrderDocumentStorage::class)->bytes($document)) === $document->sha256,
            'mime_bytes' => strlen($serialized), 'gmail_json_bytes' => strlen($gmailJson), 'work_seconds' => round($elapsed, 3), 'memory_before_bytes' => $memoryBefore,
            'php_peak_allocated_bytes' => memory_get_peak_usage(true), 'php_memory_limit' => ini_get('memory_limit'), 'php_version' => PHP_VERSION,
            'cloud_concurrent_request_capacity_proven' => false, 'slow_provider_request_budget_proven' => false];
    } elseif ($phase === 's3-wire') {
        require __DIR__.'/storage-worker-aws-readability.php';
        $requests = [];
        $disk = Storage::build(['driver' => 's3', 'key' => 'FICTIONAL-ACCESS-KEY', 'secret' => 'FICTIONAL-SECRET-NOT-CREDENTIALS',
            'region' => 'auto', 'bucket' => 'fictional-private-bucket', 'endpoint' => 'https://fictional-account.r2.cloudflarestorage.com',
            'use_path_style_endpoint' => true, 'throw' => true, 'http_handler' => function ($request) use (&$requests) {
                $requests[] = ['method' => $request->getMethod(), 'host' => $request->getUri()->getHost(), 'acl_header' => $request->getHeaderLine('x-amz-acl'),
                    'content_type' => $request->getHeaderLine('Content-Type')];
                return new \GuzzleHttp\Promise\FulfilledPromise(new \GuzzleHttp\Psr7\Response(200, ['ETag' => '"fictional-local-mock"']));
            }]);
        swAssert($disk->put('fictional.pdf', file_get_contents($root.'/inputs/sample.pdf'), ['visibility' => 'private']), 'Adapter write failed');
        swAssert(count($requests) === 1 && $requests[0]['method'] === 'PUT', 'Unexpected SDK request count');
        $checks = ['real_laravel_flysystem_aws_sdk_serialization' => true, 'network_handler_replaced_by_local_capture' => true, 'requests' => $requests,
            'actual_r2_acceptance_verified' => false, 'actual_bucket_privacy_verified' => false,
            'interpretation' => 'Private ACL header is serialized. A private staging bucket must confirm acceptance and anonymous denial; this is not a real R2 response.'];
    } elseif ($phase === 'backup') {
        swAssert(DB::table('jobs')->where('queue', 'documents')->count() === 0, 'Document queue must be empty for this quiescent backup');
        swAssert(mkdir($root.'/backup/private', 0700, true), 'Fresh backup path required');
        $path = str_replace("'", "''", $root.'/backup/fixture.sqlite');
        DB::statement("VACUUM INTO '$path'");
        swCopyTree($root.'/storage/app/private', $root.'/backup/private');
        $manifest = [];
        foreach (OrderDocument::all() as $document) {
            $manifest[] = ['id' => $document->id, 'path' => $document->path, 'sha256' => $document->sha256, 'size' => $document->size, 'version' => $document->version];
            swAssert(hash_file('sha256', $root.'/backup/private/'.$document->path) === $document->sha256, 'Backed-up PDF hash mismatch');
        }
        $checks = ['consistent_sqlite_vacuum_snapshot' => true, 'quiescent_local_file_snapshot' => true, 'documents' => $manifest, 'key_preservation_required' => true, 'cloud_backup_restore_proven' => false];
    } elseif ($phase === 'wrong-key') {
        try {
            $snapshot = OrderEmailDelivery::firstOrFail()->snapshot;
            throw new RuntimeException('Wrong APP_KEY unexpectedly decrypted snapshot');
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            $checks = ['wrong_app_key_rejected' => true];
        }
    } else {
        throw new RuntimeException('Unknown phase');
    }
    $checks['phase_seconds'] = round(microtime(true) - $started, 3);
    $checks['php_peak_allocated_bytes'] ??= memory_get_peak_usage(true);
    swJson($root.'/'.$phase.'.json', $checks);
} catch (Throwable $exception) {
    // Only harness assertions/class are printed; never fixture keys or database contents.
    fwrite(STDERR, 'Storage/worker probe failed: '.$exception::class.(in_array($exception::class, [RuntimeException::class, InvalidArgumentException::class]) ? ' '.$exception->getMessage() : '')."\n");
    exit(1);
}
