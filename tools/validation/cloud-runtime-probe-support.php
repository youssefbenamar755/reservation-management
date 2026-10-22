<?php

// Shared fail-closed bootstrap for disposable Cloud validation scripts only.
// No route/provider registration and no change to deployed application behavior.

use App\Services\DocumentValidationSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

final class CloudRuntimeAssertion extends RuntimeException {}

function crAssert(bool $ok, string $message): void
{
    if (! $ok) {
        throw new CloudRuntimeAssertion($message);
    }
}

function crNow(): float
{
    return hrtime(true) / 1e9;
}

function crBoot(array $options): array
{
    crAssert(PHP_SAPI === 'cli' && PHP_OS_FAMILY === 'Linux' && getenv('APP_ENV') === 'staging', 'Explicit Linux staging CLI required');
    crAssert(in_array('mail', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true), 'Native mail must be disabled');
    crAssert(function_exists('pcntl_alarm') && function_exists('pcntl_async_signals') && function_exists('pcntl_signal'), 'Linux signal watchdog required');
    pcntl_async_signals(true);
    pcntl_signal(SIGALRM, static function () {
        throw new CloudRuntimeAssertion('Probe watchdog deadline reached');
    });
    pcntl_alarm(45);
    $origin = rtrim((string) ($options['expected-app-url'] ?? ''), '/');
    $bucket = (string) ($options['expected-bucket'] ?? '');
    crAssert((bool) preg_match('#^https://[a-z0-9-]+\.laravel\.cloud$#D', $origin), 'Explicit approved Cloud URL required');
    crAssert((bool) preg_match('/^[a-z0-9][a-z0-9.-]{1,61}[a-z0-9]$/D', $bucket), 'Explicit approved bucket required');
    require_once dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    crAssert($app->environment('staging') && rtrim((string) config('app.url'), '/') === $origin && ! config('app.debug'), 'Exact staging origin with debug disabled required');
    $safety = app(DocumentValidationSafety::class);
    crAssert($safety->enabled() && $safety->capture() && config('document_delivery.validation.store_origins') === [], 'Capture validation with no allowed store origins required');
    Http::preventStrayRequests();
    $disk = config('filesystems.disks.documents_private', []);
    crAssert(config('document_delivery.disk') === 'documents_private' && ($disk['driver'] ?? '') === 's3' && ($disk['bucket'] ?? '') === $bucket
        && empty($disk['url']) && empty($disk['root']) && empty($disk['prefix']) && ($disk['visibility'] ?? 'private') !== 'public'
        && (bool) preg_match('#^https://[a-z0-9]+(?:\.(?:eu|fedramp))?\.r2\.cloudflarestorage\.com$#D', rtrim((string) ($disk['endpoint'] ?? ''), '/')), 'Exact private unprefixed R2 disk required');
    $db = DB::connection();
    crAssert($db->getDriverName() === 'mysql' && $db->selectOne('select database() as name')->name === 'documents_staging', 'Exact documents_staging MySQL schema required');
    $db->statement('SET SESSION innodb_lock_wait_timeout = 3');
    $db->statement('SET SESSION MAX_EXECUTION_TIME = 3000');
    crAssert(config('queue.connections.documents.driver') === 'database' && config('queue.connections.documents.table') === 'jobs'
        && in_array(config('queue.connections.documents.connection'), [null, config('database.default')], true), 'Actual staging documents database queue required');

    return [$origin, $bucket, $db];
}

function crMemory(?int $pid = null): array
{
    $status = @file_get_contents($pid === null ? '/proc/self/status' : '/proc/'.$pid.'/status');
    $values = [];
    foreach (['VmRSS' => 'rss_bytes', 'VmHWM' => 'peak_rss_bytes'] as $key => $name) {
        if (is_string($status) && preg_match('/^'.$key.':\s+(\d+)\s+kB$/m', $status, $match)) {
            $values[$name] = (int) $match[1] * 1024;
        }
    }

    return $values;
}

function crCgroup(): array
{
    $values = [];
    foreach (['memory.current', 'memory.peak', 'memory.max', 'memory.events'] as $name) {
        $value = @file_get_contents('/sys/fs/cgroup/'.$name);
        if (is_string($value)) {
            $values[$name] = trim($value);
        }
    }

    return $values;
}

function crReport(array $report): void
{
    if (function_exists('pcntl_alarm')) {
        pcntl_alarm(0);
    }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
}
