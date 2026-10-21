<?php

// One-shot, isolated Cloud managed-worker SIGTERM with a real in-flight row lock.
// No execution during preparation. Never runs a replacement worker or custom job class.
// APP_ENV=staging php -d disable_functions=mail tools/validation/cloud-worker-sigterm-probe.php --expected-app-url=https://APP.laravel.cloud --expected-bucket=BUCKET
// Run serially, with an empty staging jobs table and exactly one managed documents worker.

use App\Jobs\SyncDocumentCompletion;
use App\Services\DocumentValidationSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

const CGP_MARKER = 'wphub-cloud-worker-sigterm-v1';
const CGP_CONNECTION = 'document_sigterm_probe_only';

final class CloudGraceAssertion extends RuntimeException {}

function cgAssert(bool $condition, string $label): void
{
    if (! $condition) {
        throw new CloudGraceAssertion($label);
    }
}

function cgTime(): float
{
    return hrtime(true) / 1e9;
}

function cgWorkers(): array
{
    $workers = [];
    foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $path) {
        $raw = @file_get_contents($path);
        if (! is_string($raw)) {
            continue;
        }
        $args = explode("\0", rtrim($raw, "\0"));
        if (! preg_match('/^php[0-9.]*$/', basename($args[0] ?? ''))) {
            continue;
        }
        $artisan = null;
        foreach ($args as $index => $argument) {
            if (basename($argument) === 'artisan') {
                $artisan = $index;
                break;
            }
        }
        if ($artisan === null || ($args[$artisan + 1] ?? '') !== 'queue:work'
            || ($args[$artisan + 2] ?? '') !== 'documents' || ! in_array('--queue=documents', $args, true)) {
            continue;
        }
        $stat = @file_get_contents(dirname($path).'/stat');
        if (! is_string($stat) || ! str_contains($stat, ') ')) {
            continue;
        }
        $fields = preg_split('/\s+/', substr($stat, strrpos($stat, ') ') + 2));
        // First remaining field is process state (field 3); starttime is field 22.
        $workers[] = ['pid' => (int) basename(dirname($path)), 'start_ticks' => (string) ($fields[19] ?? '')];
    }
    usort($workers, static fn ($a, $b) => $a['pid'] <=> $b['pid']);

    return $workers;
}

function cgPrepared(object $delivery): bool
{
    return $delivery->status === 'prepared' && $delivery->document_mode === 'partial'
        && (int) $delivery->workflow === 1 && (int) $delivery->send_attempts === 0 && (int) $delivery->sync_attempts === 0
        && $delivery->sync_status === 'not_requested' && $delivery->gmail_message_id === null
        && $delivery->sent_at === null && $delivery->sync_claimed_at === null && $delivery->synced_at === null && $delivery->next_sync_at === null;
}

function cgSaveMarker($connection, int $orderId, string $runId, array $changes): void
{
    $connection->transaction(function () use ($connection, $orderId, $runId, $changes) {
        $order = $connection->table('wc_orders')->where('id', $orderId)->lockForUpdate()->first();
        cgAssert($order !== null, 'Fixture order disappeared');
        $payload = json_decode($order->payload, true, flags: JSON_THROW_ON_ERROR);
        cgAssert(($payload['worker_sigterm_probe']['run_id'] ?? '') === $runId, 'One-shot marker identity changed');
        $payload['worker_sigterm_probe'] = array_merge($payload['worker_sigterm_probe'], $changes);
        $connection->table('wc_orders')->where('id', $orderId)->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    }, 1);
}

$started = cgTime();
$deadline = $started + 48;
$stage = 'arguments';
$connection = null;
$independent = null;
$locked = false;
$markerCreated = false;
$orderId = null;
$runId = null;
$failed = false;
$report = ['ok' => false, 'probe' => CGP_MARKER, 'mail_sent' => false, 'store_calls' => 0];
try {
    cgAssert(PHP_SAPI === 'cli', 'CLI only');
    $options = getopt('', ['expected-app-url:', 'expected-bucket:', 'help']);
    if (isset($options['help'])) {
        echo "APP_ENV=staging php -d disable_functions=mail tools/validation/cloud-worker-sigterm-probe.php --expected-app-url=https://APP.laravel.cloud --expected-bucket=BUCKET\n";
        echo "One-shot: locks the original prepared partial fixture, enqueues its real SyncDocumentCompletion, sends SIGTERM to the exact managed worker while reserved, then releases and verifies unchanged state. No email/store call. Refuses existing marker or nonempty queue.\n";
        exit(0);
    }
    $origin = rtrim((string) ($options['expected-app-url'] ?? ''), '/');
    $bucket = (string) ($options['expected-bucket'] ?? '');
    cgAssert((bool) preg_match('#^https://[a-z0-9-]+\.laravel\.cloud$#D', $origin), 'Explicit approved Cloud URL required');
    cgAssert((bool) preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket), 'Explicit approved bucket required');
    cgAssert(getenv('APP_ENV') === 'staging' && PHP_OS_FAMILY === 'Linux', 'Explicit staging process on Linux required');
    cgAssert(in_array('mail', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true), 'Native PHP mail must be disabled');
    cgAssert(function_exists('pcntl_alarm') && function_exists('pcntl_async_signals') && function_exists('pcntl_signal'), 'Linux pcntl watchdog required');
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function () {
        throw new CloudGraceAssertion('Probe watchdog deadline reached; no replay allowed');
    });
    // Leave up to six seconds for bounded rollback/marker cleanup before 60s.
    pcntl_alarm(51);

    $stage = 'bootstrap';
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    $stage = 'isolation';
    cgAssert($app->environment('staging') && rtrim((string) config('app.url'), '/') === $origin && ! config('app.debug'), 'Exact staging URL/environment and debug=false required');
    $safety = app(DocumentValidationSafety::class);
    cgAssert($safety->enabled() && $safety->capture() && config('document_delivery.validation.store_origins') === [], 'Validation capture and empty store allowlist required');
    Http::preventStrayRequests();
    $disk = config('filesystems.disks.documents_private', []);
    cgAssert(config('document_delivery.disk') === 'documents_private' && ($disk['driver'] ?? '') === 's3'
        && ($disk['bucket'] ?? '') === $bucket && empty($disk['root']) && empty($disk['prefix']) && empty($disk['url'])
        && ($disk['visibility'] ?? 'private') !== 'public'
        && (bool) preg_match('#^https://[a-z0-9]+(?:\.(?:eu|fedramp))?\.r2\.cloudflarestorage\.com$#D', rtrim((string) ($disk['endpoint'] ?? ''), '/')), 'Exact private R2 configuration required');
    $default = config('database.default');
    cgAssert(! config('database.connections.'.CGP_CONNECTION), 'Probe connection name already exists');
    $database = config('database.connections.'.$default);
    cgAssert(is_array($database) && ($database['driver'] ?? '') === 'mysql', 'MySQL required');
    $database['options'][PDO::ATTR_TIMEOUT] = 3;
    config(['database.connections.'.$default => $database, 'database.connections.'.CGP_CONNECTION => $database]);
    $connection = DB::connection($default);
    $independent = DB::connection(CGP_CONNECTION);
    foreach ([$connection, $independent] as $db) {
        cgAssert($db->selectOne('select database() as name')->name === 'documents_staging', 'Exact documents_staging schema required');
        $db->statement('SET SESSION innodb_lock_wait_timeout = 3');
        $db->statement('SET SESSION MAX_EXECUTION_TIME = 3000');
    }
    cgAssert($connection->selectOne('select connection_id() as id')->id !== $independent->selectOne('select connection_id() as id')->id, 'A distinct database connection is required');
    cgAssert(config('queue.connections.documents.driver') === 'database' && config('queue.connections.documents.table') === 'jobs'
        && in_array(config('queue.connections.documents.connection'), [null, $default], true)
        && config('queue.failed.driver') === 'database-uuids' && config('queue.failed.database') === $default
        && config('queue.failed.table') === 'failed_jobs', 'Actual staging documents queue/failure store required');
    config(['queue.connections.documents.connection' => CGP_CONNECTION]);
    cgAssert(function_exists('posix_kill'), 'POSIX signal support required');

    $stage = 'fixture-and-worker';
    cgAssert(! $independent->table('jobs')->exists(), 'Staging queue must be empty');
    $site = $independent->table('websites')->where('slug', 'fictional-cloud-documents-probe')->first();
    cgAssert($site && $site->status === 'paused' && $site->base_url === 'https://cloud-probe.example.invalid', 'Known paused fictional site required');
    $owner = $independent->table('users')->where('id', $site->user_id)->first();
    cgAssert($owner && ! $owner->is_admin && $owner->email === 'cloud-probe-owner@example.invalid', 'Fictional non-admin owner required');
    $order = $independent->table('wc_orders')->where('website_id', $site->id)->where('wp_order_id', 900001)->first();
    cgAssert($order && $order->status === 'processing', 'Original fictional processing order required');
    $payload = json_decode($order->payload, true, flags: JSON_THROW_ON_ERROR);
    cgAssert(($payload['staging_probe'] ?? '') === 'wphub-cloud-documents-v1' && isset($payload['delivery_id'])
        && ! isset($payload['worker_sigterm_probe']), 'Recognized original fixture without prior grace marker required');
    $deliveryId = $payload['delivery_id'];
    $delivery = $independent->table('order_email_deliveries')->where('id', $deliveryId)->first();
    cgAssert($delivery && (int) $delivery->wc_order_id === (int) $order->id && cgPrepared($delivery), 'Original prepared partial delivery required');
    $gmail = $independent->table('gmail_connections')->where('id', $delivery->gmail_connection_id)->first();
    cgAssert($gmail && $gmail->connected_at === null && $gmail->access_token === null && $gmail->refresh_token === null, 'Disconnected token-free Gmail metadata required');
    $workers = cgWorkers();
    cgAssert(count($workers) === 1, 'Exactly one visible managed documents worker required');
    $beforeWorker = $workers[0];
    $orderId = (int) $order->id;
    $runId = (string) Str::uuid();
    $failedBaseline = (int) ($independent->table('failed_jobs')->max('id') ?? 0);
    $independent->transaction(function () use ($independent, $orderId, $runId, $beforeWorker, $failedBaseline) {
        $lockedOrder = $independent->table('wc_orders')->where('id', $orderId)->lockForUpdate()->first();
        $data = json_decode($lockedOrder->payload, true, flags: JSON_THROW_ON_ERROR);
        cgAssert(! isset($data['worker_sigterm_probe']), 'Grace probe marker already exists; never replay');
        $data['worker_sigterm_probe'] = ['marker' => CGP_MARKER, 'run_id' => $runId, 'state' => 'started',
            'started_at' => gmdate(DATE_ATOM), 'worker_before' => $beforeWorker, 'failed_jobs_max_id' => $failedBaseline];
        $independent->table('wc_orders')->where('id', $orderId)->update(['payload' => json_encode($data, JSON_THROW_ON_ERROR)]);
    }, 1);
    $markerCreated = true;
    $report += ['run_id' => $runId, 'worker_before' => $beforeWorker, 'delivery_id' => $deliveryId];

    $stage = 'lock-and-dispatch';
    cgAssert(cgTime() < $deadline - 30, 'Insufficient time left for bounded lock/SIGTERM check');
    $connection->beginTransaction();
    $locked = true;
    $delivery = $connection->table('order_email_deliveries')->where('id', $deliveryId)->lockForUpdate()->first();
    cgAssert($delivery && cgPrepared($delivery), 'Delivery changed before lock acquisition');
    $lockStarted = cgTime();
    $lockDeadline = $lockStarted + 16;
    $jobId = null;
    $jobUuid = null;
    $independent->transaction(function () use ($independent, $deliveryId, $orderId, $runId, &$jobId, &$jobUuid) {
        cgAssert(! $independent->table('jobs')->exists(), 'Queue changed before dispatch');
        // Explicit beforeCommit avoids Laravel deferring insertion behind the held
        // default-connection transaction. The independent transaction still makes
        // the job/one-shot marker visible atomically to the real managed worker.
        $jobId = Queue::connection('documents')->push((new SyncDocumentCompletion($deliveryId))->beforeCommit(), '', 'documents');
        cgAssert(is_numeric($jobId), 'Real document job insert failed');
        $row = $independent->table('jobs')->where('id', $jobId)->first();
        $job = $row ? json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR) : [];
        cgAssert(($job['displayName'] ?? '') === SyncDocumentCompletion::class && Str::isUuid($job['uuid'] ?? ''), 'Unexpected job payload');
        $jobUuid = $job['uuid'];
        cgSaveMarker($independent, $orderId, $runId, ['state' => 'enqueued', 'job_id' => (int) $jobId, 'job_uuid' => $jobUuid]);
    }, 1);
    $report += ['job_id' => (int) $jobId, 'job_uuid' => $jobUuid];

    $stage = 'observe-reserved';
    $reservationDeadline = min($lockStarted + 6, $deadline);
    do {
        $job = $independent->table('jobs')->where('id', $jobId)->first();
        cgAssert($job !== null, 'Job disappeared while its delivery row was locked');
        if ($job->reserved_at !== null) {
            break;
        }
        cgAssert(cgTime() < $reservationDeadline, 'Managed worker did not reserve the job within six seconds');
        usleep(200000);
    } while (true);
    cgAssert((int) $job->attempts === 1 && cgWorkers() === [$beforeWorker], 'Unexpected reservation attempt or worker identity');
    $reservedAt = (int) $job->reserved_at;
    cgSaveMarker($independent, $orderId, $runId, ['state' => 'reserved_under_lock', 'reserved_at' => $reservedAt, 'attempts_observed' => 1]);

    $stage = 'actual-posix-sigterm';
    cgAssert(cgTime() < $lockDeadline - 9, 'Insufficient bounded lock budget for signal/drain observation');
    cgAssert(cgWorkers() === [$beforeWorker] && $beforeWorker['pid'] > 1 && $beforeWorker['pid'] !== getmypid(), 'Managed worker identity changed before signal');
    cgAssert(posix_kill($beforeWorker['pid'], SIGTERM), 'SIGTERM could not be delivered to the exact managed worker');
    $signalObserved = cgTime();
    cgSaveMarker($independent, $orderId, $runId, ['state' => 'sigterm_delivered_under_lock', 'signal' => 'SIGTERM']);
    $holdUntil = min($signalObserved + 7, $lockDeadline);
    cgAssert($holdUntil - $signalObserved >= 4, 'At least four seconds of in-flight observation are required');
    $stage = 'hold-after-sigterm';
    do {
        $job = $independent->table('jobs')->where('id', $jobId)->first();
        cgAssert($job && (int) $job->attempts === 1 && (int) $job->reserved_at === $reservedAt,
            'Reserved job was lost or retried before releasing its row lock');
        cgAssert(cgWorkers() === [$beforeWorker], 'Managed worker exited before the in-flight job could finish');
        cgAssert(cgTime() < $lockDeadline && cgTime() < $deadline, 'Bounded lock deadline reached');
        usleep(200000);
    } while (cgTime() < $holdUntil);

    $stage = 'release-and-observe';
    $connection->commit();
    $locked = false;
    $released = cgTime();
    cgAssert($released - $lockStarted < 20, 'Observed row-lock duration exceeded the twenty-second bound');
    cgSaveMarker($independent, $orderId, $runId, ['state' => 'lock_released', 'released_at' => gmdate(DATE_ATOM),
        'lock_held_seconds' => round($released - $lockStarted, 3), 'post_signal_hold_seconds' => round($released - $signalObserved, 3)]);
    $afterWorker = null;
    $completionDeadline = min($released + 19, $deadline);
    do {
        $remaining = $independent->table('jobs')->where('id', $jobId)->exists();
        $currentWorkers = cgWorkers();
        if (! $remaining && count($currentWorkers) === 1 && $currentWorkers[0] !== $beforeWorker) {
            $afterWorker = $currentWorkers[0];
            break;
        }
        cgAssert(cgTime() < $completionDeadline, 'Job consumption and supervisor replacement were not observed within the bounded window');
        usleep(200000);
    } while (true);
    $stage = 'final-state';
    $delivery = $independent->table('order_email_deliveries')->where('id', $deliveryId)->first();
    cgAssert($delivery && cgPrepared($delivery), 'Prepared partial delivery state changed');
    cgAssert($independent->table('wc_orders')->where('id', $orderId)->value('status') === 'processing', 'Fictional order status changed');
    cgAssert(! $independent->table('failed_jobs')->where('id', '>', $failedBaseline)->exists()
        && ! $independent->table('failed_jobs')->where('uuid', $jobUuid)->exists(), 'New failed job observed');
    cgAssert(! $independent->table('jobs')->exists(), 'Queue is not empty after the single grace job');
    $result = ['state' => 'passed', 'worker_after' => $afterWorker, 'send_attempts' => 0, 'sync_attempts' => 0,
        'reserved_at_observed' => $reservedAt, 'reserved_attempts_observed' => 1, 'new_failed_jobs' => 0,
        'lock_held_seconds' => round($released - $lockStarted, 3), 'post_signal_hold_seconds' => round($released - $signalObserved, 3),
        'elapsed_seconds' => round(cgTime() - $started, 3), 'finished_at' => gmdate(DATE_ATOM)];
    cgSaveMarker($independent, $orderId, $runId, $result);
    $report = array_merge($report, $result, ['ok' => true, 'queue_jobs_dispatched' => 1,
        'limits' => ['Actual managed worker, database contention and POSIX SIGTERM delivery; no mocked worker or job.',
            'Prepared partial delivery returns without claiming, contacting a store or sending mail after the lock releases.',
            'This checks a short in-flight row wait across an actual SIGTERM and supervisor replacement. It does not prove a deployment rollout signal, the full configured shutdown window, a 90-second timeout, provider delay or concurrency capacity.']]);
} catch (Throwable $exception) {
    $failed = true;
    $report += ['failed_stage' => $stage, 'exception_class' => get_class($exception),
        'assertion' => $exception instanceof CloudGraceAssertion ? $exception->getMessage() : 'Operation failed; inspect private logs without publishing secrets.'];
} finally {
    if ($locked && $connection) {
        try {
            $connection->rollBack();
        } catch (Throwable) {
            $connection->disconnect(); // Closing our connection releases its fixture row lock.
        }
        $locked = false;
    }
    if ($failed && $markerCreated && $independent) {
        try {
            cgSaveMarker($independent, $orderId, $runId, ['state' => 'stopped_no_replay', 'failed_stage' => $stage,
                'finished_at' => gmdate(DATE_ATOM)]);
        } catch (Throwable) {
            $report['failure_marker_update'] = 'not confirmed; existing one-shot marker remains';
        }
        $report['recovery'] = 'Fixture lock released; any queued job is preserved. Inspect its recorded ID and state; do not replay this probe.';
    }
    if (function_exists('pcntl_alarm')) {
        pcntl_alarm(0);
    }
}

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
exit($failed ? 1 : 0);
