<?php

// Actual two-process Cloud/R2/MySQL read + MIME serialization sample, never mail.
// Run after cloud-capacity-probe.php. Both children read immutable fictional data.
// APP_ENV=staging php -d disable_functions=mail tools/validation/cloud-concurrency-probe.php --expected-app-url=https://APP.laravel.cloud --expected-bucket=BUCKET

use App\Models\OrderDocument;
use App\Models\OrderEmailDelivery;
use App\Models\WcOrder;
use App\Models\Website;
use App\Services\OrderDocumentStorage;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Mime\Email;
use Symfony\Component\Process\Process;

require __DIR__.'/cloud-runtime-probe-support.php';

$started = crNow();
$stage = 'bootstrap';
$children = [];
$report = ['ok' => false, 'probe' => 'wphub-cloud-two-process-v1', 'mail_sent' => false, 'store_calls' => 0, 'queue_jobs_dispatched' => 0];
try {
    $options = getopt('', ['expected-app-url:', 'expected-bucket:', 'lane:', 'barrier:']);
    [$origin, $bucket] = crBoot($options);
    $site = Website::where('slug', 'fictional-cloud-documents-probe')->firstOrFail();
    crAssert($site->status === 'paused' && $site->base_url === 'https://cloud-probe.example.invalid', 'Exact paused fictional site required');
    $order = WcOrder::where('website_id', $site->id)->where('wp_order_id', 900002)->firstOrFail();
    $marker = $order->payload['capacity_probe'] ?? [];
    crAssert(($order->payload['staging_probe'] ?? '') === 'wphub-cloud-documents-v1' && ($marker['marker'] ?? '') === 'wphub-cloud-capacity-v1', 'Previously verified fictional capacity data required');
    $delivery = OrderEmailDelivery::findOrFail($marker['delivery_id'] ?? '');
    crAssert($delivery->wc_order_id === $order->id && $delivery->status === 'prepared' && $delivery->send_attempts === 0
        && $delivery->sync_attempts === 0 && $delivery->gmail_message_id === null && $delivery->document_mode === 'partial', 'Unsent immutable capacity fixture required');
    $baseline = [DB::table('jobs')->count(), DB::table('failed_jobs')->count(), $delivery->send_attempts, $delivery->sync_attempts];
    $lane = $options['lane'] ?? null;
    if ($lane !== null) {
        $stage = 'child-barrier';
        crAssert(in_array($lane, ['a', 'b'], true), 'One of two child lanes required');
        $barrier = (string) ($options['barrier'] ?? '');
        crAssert(preg_match('/^[a-f0-9]{32}$/D', $barrier) === 1, 'Exact generated barrier identifier required');
        $directory = rtrim(sys_get_temp_dir(), '/').'/wphub-concurrency-'.$barrier;
        $manifest = json_decode((string) file_get_contents($directory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
        crAssert(($manifest['origin'] ?? '') === $origin && ($manifest['bucket'] ?? '') === $bucket && ($manifest['delivery_id'] ?? '') === $delivery->id
            && ($manifest['created_at'] ?? 0) > time() - 45, 'Fresh exact parent barrier required');
        file_put_contents($directory.'/'.$lane.'.ready', (string) getmypid(), LOCK_EX);
        while (! is_file($directory.'/go')) {
            crAssert(crNow() - $started < 12, 'Parent barrier was not released');
            usleep(50000);
        }
        $work = crNow();
        $documents = OrderDocument::where('wc_order_id', $order->id)->orderBy('version')->get();
        crAssert($documents->count() === 2 && $documents->pluck('id')->all() === $marker['document_ids'], 'Exact two capacity documents required');
        $email = (new Email)->from('cloud-probe-sender@example.invalid')->to('cloud-probe-recipient@example.invalid')
            ->subject('[FICTIONAL CONCURRENT PROBE] Never sent')->text('Fictional private document processing sample only.');
        foreach ($documents as $document) {
            crAssert($document->size === 5 * 1024 * 1024 && $document->disk === 'documents_private', 'Expected private five MiB document required');
            $bytes = app(OrderDocumentStorage::class)->bytes($document);
            crAssert(hash('sha256', $bytes) === $document->sha256, 'Actual R2 PDF hash mismatch');
            $email->attach($bytes, $document->name, 'application/pdf');
        }
        $constructed = $email->toString();
        $decrypted = $delivery->mime;
        crAssert(hash('sha256', $decrypted) === $marker['mime_sha256'], 'Actual immutable MIME decryption mismatch');
        $json = json_encode(['raw' => rtrim(strtr(base64_encode($decrypted), '+/', '-_'), '=')], JSON_THROW_ON_ERROR);
        $peak = ['pid' => getmypid(), 'lane' => $lane, 'work_seconds' => round(crNow() - $work, 3),
            'php_peak_allocated_bytes' => memory_get_peak_usage(true), 'memory' => crMemory(), 'mime_bytes' => strlen($decrypted),
            'gmail_json_bytes' => strlen($json), 'constructed_mime_bytes' => strlen($constructed)];
        file_put_contents($directory.'/'.$lane.'.holding', '1', LOCK_EX);
        // Both retain real buffers until the parent has sampled their overlap, capped.
        while (! is_file($directory.'/release')) {
            crAssert(crNow() - $started < 34, 'Parent did not release retained buffers');
            usleep(50000);
        }
        crReport($peak + ['ok' => true, 'mail_sent' => false]);
        exit(0);
    }
    $stage = 'parent-start';
    $barrier = bin2hex(random_bytes(16));
    $directory = rtrim(sys_get_temp_dir(), '/').'/wphub-concurrency-'.$barrier;
    crAssert(mkdir($directory, 0700), 'Could not create private barrier directory');
    file_put_contents($directory.'/manifest.json', json_encode(['origin' => $origin, 'bucket' => $bucket, 'delivery_id' => $delivery->id, 'created_at' => time()], JSON_THROW_ON_ERROR), LOCK_EX);
    $before = crCgroup();
    foreach (['a', 'b'] as $lane) {
        $child = new Process([PHP_BINARY, '-d', 'disable_functions=mail', __FILE__, '--expected-app-url='.$origin, '--expected-bucket='.$bucket, '--lane='.$lane, '--barrier='.$barrier], dirname(__DIR__, 2));
        $child->setTimeout(38);
        $child->start();
        $children[$lane] = $child;
    }
    while (! is_file($directory.'/a.ready') || ! is_file($directory.'/b.ready')) {
        crAssert(crNow() - $started < 12, 'Both isolated children did not become ready');
        foreach ($children as $child) {
            crAssert($child->isRunning(), 'A child exited before the barrier');
        }
        usleep(50000);
    }
    file_put_contents($directory.'/go', '1', LOCK_EX);
    $stage = 'measure-two-process-overlap';
    $maxCgroupCurrent = 0;
    $maxChildRss = 0;
    $overlapSamples = 0;
    $overlapStarted = null;
    do {
        $sum = 0;
        foreach ($children as $child) {
            crAssert($child->isRunning(), 'A child exited before concurrent observation finished');
            $sum += crMemory($child->getPid())['rss_bytes'] ?? 0;
        }
        $maxChildRss = max($maxChildRss, $sum);
        $current = crCgroup();
        $maxCgroupCurrent = max($maxCgroupCurrent, (int) ($current['memory.current'] ?? 0));
        if (is_file($directory.'/a.holding') && is_file($directory.'/b.holding')) {
            $overlapStarted ??= crNow();
            $overlapSamples++;
        }
        crAssert(crNow() - $started < 32, 'Concurrent sample exceeded bounded run window');
        usleep(100000);
    } while ($overlapStarted === null || crNow() - $overlapStarted < 2);
    file_put_contents($directory.'/release', '1', LOCK_EX);
    $results = [];
    foreach ($children as $lane => $child) {
        $child->wait();
        crAssert($child->isSuccessful(), 'Concurrent child did not finish successfully');
        $result = json_decode($child->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        crAssert(($result['ok'] ?? false) === true && ($result['lane'] ?? '') === $lane, 'Unexpected child result');
        $results[] = $result;
    }
    crAssert([DB::table('jobs')->count(), DB::table('failed_jobs')->count(), $delivery->fresh()->send_attempts, $delivery->fresh()->sync_attempts] === $baseline, 'Queue or delivery counters changed');
    $report += ['origin' => $origin, 'runtime' => PHP_VERSION, 'operations' => 2, 'child_results' => $results,
        'overlap_samples' => $overlapSamples, 'overlap_seconds' => round(crNow() - $overlapStarted, 3), 'peak_observed_children_rss_bytes' => $maxChildRss,
        'peak_observed_cgroup_current_bytes' => $maxCgroupCurrent, 'cgroup_before' => $before, 'cgroup_after' => crCgroup(),
        'elapsed_seconds' => round(crNow() - $started, 3), 'php_memory_limit' => ini_get('memory_limit'),
        'limits' => ['Two actual concurrent Linux CLI/R2/decrypt/MIME/JSON operations; no writes to documents or delivery rows.',
            'Retained diagnostic buffers add memory. This is not concurrent PHP-FPM send capacity or a load limit recommendation.',
            'Cgroup readings include other processes and memory. Historical memory.peak is not attributed to this run; use sampled memory.current.']];
    $report['ok'] = true;
} catch (Throwable $exception) {
    $report += ['failed_stage' => $stage, 'exception_class' => get_class($exception),
        'assertion' => $exception instanceof CloudRuntimeAssertion ? $exception->getMessage() : 'Operation failed; inspect private logs without publishing credentials.'];
} finally {
    foreach ($children as $child) {
        if ($child->isRunning()) {
            $child->stop(1); // Only this invocation's two private child processes.
        }
    }
}
crReport($report);
exit($report['ok'] ? 0 : 1);
