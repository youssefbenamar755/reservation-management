<?php

// Final runtime fixture and bounded deadline fault. No public route/test hook added.
// Seed once after the original Cloud storage seed. Root separately assigns an
// ephemeral password to this fictional owner and uses ordinary HTTPS login/routes.
// Start the HTTPS caller polling deadline GET before phase=deadline-lock; once
// result_message equals the returned ready_message, submit exactly ONE send POST.
// APP_ENV=staging php -d disable_functions=mail tools/validation/cloud-runtime-probe.php --phase=seed|deadline-lock|verify --expected-app-url=https://APP.laravel.cloud --expected-bucket=BUCKET

use App\Models\GmailAppSetting;
use App\Models\GmailConnection;
use App\Models\OrderEmailDelivery;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Models\WebsiteDocumentProfile;
use App\Services\BrandedDocumentDelivery;
use App\Services\BrandedDocumentRenderer;
use App\Services\GmailClient;
use App\Services\OrderDocumentStorage;
use Dompdf\Dompdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/cloud-runtime-probe-support.php';

const CRP_MARKER = 'wphub-cloud-final-runtime-v1';
const CRP_SLUG = 'fictional-cloud-runtime-probe';
const CRP_READY = 'Fictional staging deadline lock ready; submit the approved probe once.';

function crCaptureCount(): int
{
    return count(glob(rtrim((string) config('document_delivery.validation.capture_path'), '/').'/*.eml') ?: []);
}

$started = crNow();
$stage = 'bootstrap';
$locked = false;
$temporary = null;
$db = null;
$report = ['ok' => false, 'probe' => CRP_MARKER, 'mail_sent' => false, 'store_calls' => 0];
try {
    $options = getopt('', ['phase:', 'expected-app-url:', 'expected-bucket:']);
    [$origin, $bucket, $db] = crBoot($options);
    $phase = $options['phase'] ?? '';
    crAssert(in_array($phase, ['seed', 'deadline-lock', 'verify'], true), 'Explicit supported phase required');
    crAssert(in_array('cloud-probe-recipient@example.invalid', config('document_delivery.validation.recipients'), true), 'Fictional recipient must be explicitly capture-allowlisted');
    crAssert(Website::where('slug', 'fictional-cloud-documents-probe')->where('status', 'paused')->where('base_url', 'https://cloud-probe.example.invalid')->exists(), 'Original isolated storage seed required');
    $site = Website::where('slug', CRP_SLUG)->first();
    if ($phase === 'seed') {
        crAssert($site === null && ! User::where('email', 'cloud-runtime-owner@example.invalid')->exists()
            && ! DB::table('jobs')->exists(), 'Fresh one-shot runtime fixture and empty queue required');
        crAssert(! GmailAppSetting::exists(), 'Fresh staging must have no OAuth application credentials');
        $pdf = new Dompdf;
        $pdf->set_option('isRemoteEnabled', false);
        $pdf->loadHtml('<h1>FICTIONAL runtime validation</h1><p>Never a booking or travel document. No email will be sent by this probe.</p>');
        $pdf->render();
        $temporary = tempnam(sys_get_temp_dir(), 'wphub-runtime-');
        crAssert(is_string($temporary) && file_put_contents($temporary, $pdf->output()) !== false, 'Private fictional PDF could not be written');
        chmod($temporary, 0600);
        $site = DB::transaction(function () use ($temporary) {
            GmailAppSetting::create(['id' => 1, 'client_id' => 'fictional-runtime-client.invalid', 'client_secret' => 'fictional-runtime-secret-not-a-credential']);
            $owner = User::create(['name' => 'Fictional runtime owner', 'email' => 'cloud-runtime-owner@example.invalid',
                'password' => bin2hex(random_bytes(32)), 'is_admin' => false]);
            $site = Website::create(['user_id' => $owner->id, 'name' => 'Fictional Cloud runtime validation', 'slug' => CRP_SLUG,
                'base_url' => 'https://runtime.example.invalid', 'status' => 'paused']);
            $connection = GmailConnection::create(['user_id' => $owner->id, 'email' => 'cloud-runtime-sender@example.invalid',
                'connection_key' => (string) Str::uuid(), 'app_fingerprint' => app(GmailClient::class)->fingerprint(),
                'access_token' => 'fictional-runtime-access-not-a-token', 'refresh_token' => 'fictional-runtime-refresh-not-a-token',
                'expires_at' => now()->addHour(), 'connected_at' => now(),
                'aliases' => [['email' => 'cloud-runtime-sender@example.invalid', 'name' => 'Fictional runtime validation']]]);
            $profile = WebsiteDocumentProfile::create(['website_id' => $site->id, 'brand_name' => 'Fictional runtime validation',
                'logo_url' => 'https://runtime.example.invalid/fictional-logo.png', 'primary_color' => '#143e50', 'accent_color' => '#248070',
                'background_color' => '#f5f7fa', 'text_color' => '#102030', 'support_email' => 'support@example.invalid',
                'website_url' => 'https://runtime.example.invalid', 'footer' => 'FICTIONAL validation only',
                'gmail_connection_id' => $connection->id, 'sender_email' => $connection->email, 'sender_name' => 'Fictional runtime validation',
                'reply_to' => 'support@example.invalid', 'default_language' => 'en', 'templates' => BrandedDocumentRenderer::defaults(),
                'service_label' => 'fictional sample', 'required_services' => ['Flight'], 'workflow_enabled' => true, 'config_version' => 1,
                'store_pairing_key' => (string) Str::uuid(), 'brand_sync_version' => 1]);
            $order = WcOrder::withoutEvents(fn () => WcOrder::create(['website_id' => $site->id, 'wp_order_id' => 900004,
                'status' => 'processing', 'customer_email' => 'cloud-probe-recipient@example.invalid', 'customer_name' => 'Fictional deadline customer',
                'payload' => ['runtime_probe' => CRP_MARKER, 'purpose' => 'deadline-before-capture', 'locale' => 'en_US']]));
            $documents = app(OrderDocumentStorage::class)->upload($order, $owner,
                [new UploadedFile($temporary, 'Fictional runtime sample.pdf', 'application/pdf', null, true)], $order->customer_email, true);
            $delivery = app(BrandedDocumentDelivery::class)->prepare($order, $owner, ['document_ids' => array_column($documents, 'id'),
                'recipient' => $order->customer_email, 'mode' => 'partial']);
            $payload = $order->payload;
            $payload += ['delivery_id' => $delivery->id, 'capture_count_before' => crCaptureCount(), 'failed_jobs_before' => DB::table('failed_jobs')->count()];
            WcOrder::withoutEvents(fn () => $order->update(['payload' => $payload]));
            $retryOrder = WcOrder::withoutEvents(fn () => WcOrder::create(['website_id' => $site->id, 'wp_order_id' => 900005,
                'status' => 'processing', 'customer_email' => $order->customer_email,
                'payload' => ['runtime_probe' => CRP_MARKER, 'purpose' => 'queue-validation-failure-only']]));
            $snapshot = $delivery->snapshot;
            $snapshot = array_replace($snapshot, ['mode' => 'complete', 'attachments' => [], 'fulfillment_confirmed' => false,
                'required_services' => ['Flight'], 'fulfilled_services' => [], 'website_identity' => $profile->store_pairing_key,
                'config_version' => 1, 'validation_probe' => CRP_MARKER]);
            // Intentionally synthetic capture-only state: this is NOT provider acceptance.
            // Missing fulfillment proof makes the real worker reject before any store call.
            $retry = OrderEmailDelivery::create(['user_id' => $owner->id, 'wc_order_id' => $retryOrder->id, 'website_id' => $site->id,
                'gmail_connection_id' => $connection->id, 'connection_key' => $connection->connection_key,
                'fingerprint' => hash('sha256', CRP_MARKER.'-retry'), 'snapshot' => $snapshot, 'mime' => null,
                'workflow' => true, 'document_mode' => 'complete', 'status' => 'sent', 'gmail_message_id' => 'capture_runtime_queue_fixture',
                'sent_at' => now(), 'send_attempts' => 0, 'sync_status' => 'pending', 'next_sync_at' => now()->addHour(),
                'expires_at' => now()->addDay(), 'result_message' => 'Fictional capture-state fixture; never emailed.']);
            WcOrder::withoutEvents(fn () => $retryOrder->update(['payload' => $retryOrder->payload + ['delivery_id' => $retry->id]]));

            return $site;
        });
    }
    $stage = 'fixture-scope';
    crAssert($site && $site->status === 'paused' && $site->base_url === 'https://runtime.example.invalid'
        && ! $site->wc_consumer_key && ! $site->wc_consumer_secret, 'Exact fictional paused site without store credentials required');
    $owner = User::findOrFail($site->user_id);
    crAssert(! $owner->is_admin && $owner->email === 'cloud-runtime-owner@example.invalid', 'Exact fictional owner required');
    $setting = GmailAppSetting::findOrFail(1);
    crAssert($setting->client_id === 'fictional-runtime-client.invalid' && $setting->client_secret === 'fictional-runtime-secret-not-a-credential', 'Only fake OAuth values are allowed');
    $connection = GmailConnection::where('user_id', $owner->id)->sole();
    crAssert($connection->access_token === 'fictional-runtime-access-not-a-token' && $connection->refresh_token === 'fictional-runtime-refresh-not-a-token', 'Only fake token values are allowed');
    $order = WcOrder::where('website_id', $site->id)->where('wp_order_id', 900004)->sole();
    $retryOrder = WcOrder::where('website_id', $site->id)->where('wp_order_id', 900005)->sole();
    crAssert(($order->payload['runtime_probe'] ?? '') === CRP_MARKER && ($retryOrder->payload['runtime_probe'] ?? '') === CRP_MARKER, 'Exact runtime order markers required');
    $delivery = OrderEmailDelivery::whereKey($order->payload['delivery_id'])->where('wc_order_id', $order->id)->sole();
    $retry = OrderEmailDelivery::whereKey($retryOrder->payload['delivery_id'])->where('wc_order_id', $retryOrder->id)->sole();
    crAssert($delivery->document_mode === 'partial' && $retry->document_mode === 'complete'
        && $retry->gmail_message_id === 'capture_runtime_queue_fixture' && ($retry->snapshot['fulfillment_confirmed'] ?? null) === false, 'Distinct deadline and intentionally invalid queue fixtures required');
    if ($phase === 'deadline-lock') {
        $stage = 'bounded-order-lock';
        crAssert(function_exists('pcntl_alarm') && function_exists('pcntl_signal') && function_exists('pcntl_async_signals'), 'Linux signal watchdog required');
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function () {
            throw new CloudRuntimeAssertion('Bounded deadline lock watchdog expired');
        });
        pcntl_alarm(42);
        crAssert($delivery->status === 'prepared' && $delivery->send_attempts === 0 && ! isset($order->payload['deadline_lock']), 'Fresh prepared deadline fixture; never replay a lock test');
        $payload = $order->payload + ['deadline_lock' => ['state' => 'started', 'started_at' => gmdate(DATE_ATOM)]];
        WcOrder::withoutEvents(fn () => $order->update(['payload' => $payload]));
        $default = config('database.default');
        crAssert(! config('database.connections.runtime_probe_marker'), 'Probe connection name must be unused');
        config(['database.connections.runtime_probe_marker' => config('database.connections.'.$default)]);
        $markerDb = DB::connection('runtime_probe_marker');
        crAssert($markerDb->selectOne('select database() as name')->name === 'documents_staging', 'Exact independent marker schema required');
        $markerDb->statement('SET SESSION innodb_lock_wait_timeout = 3');
        $db->beginTransaction();
        $locked = true;
        $db->table('wc_orders')->where('id', $order->id)->lockForUpdate()->first();
        // The send deadline begins before its order claim. Publish readiness only
        // after this real row lock exists, using the independently unlocked delivery.
        $markerDb->table('order_email_deliveries')->where('id', $delivery->id)->update(['result_message' => CRP_READY]);
        $holdStarted = crNow();
        while (crNow() - $holdStarted < 25) {
            usleep(100000);
        }
        $db->commit();
        $locked = false;
        $holdSeconds = crNow() - $holdStarted;
        $waitUntil = crNow() + 7;
        do {
            $delivery->refresh();
            if (! in_array($delivery->status, ['prepared', 'sending'], true)) {
                break;
            }
            crAssert(crNow() < $waitUntil, 'The approved HTTPS send did not reach the fixture in time; inspect, do not replay');
            usleep(100000);
        } while (true);
        crAssert($delivery->status === 'failed' && $delivery->send_attempts === 1 && str_contains($delivery->result_message ?? '', 'No email was submitted')
            && $delivery->gmail_message_id === null && $delivery->sent_at === null, 'Expected definite deadline failure was not recorded');
        $payload = $order->fresh()->payload;
        $payload['deadline_lock'] = ['state' => 'passed', 'lock_held_seconds' => round($holdSeconds, 3), 'finished_at' => gmdate(DATE_ATOM)];
        WcOrder::withoutEvents(fn () => $order->update(['payload' => $payload]));
        $report['lock_held_seconds'] = round($holdSeconds, 3);
    }
    if ($phase === 'verify') {
        crAssert(($order->payload['deadline_lock']['state'] ?? '') === 'passed' && $delivery->status === 'failed'
            && $delivery->send_attempts === 1 && $delivery->gmail_message_id === null && $delivery->sent_at === null
            && str_contains($delivery->result_message ?? '', 'No email was submitted'), 'Definite no-submission deadline outcome required');
        crAssert($retry->status === 'sent' && $retry->sync_status === 'pending' && $retry->sync_attempts === 1 && $retry->send_attempts === 0
            && $retry->next_sync_at?->isFuture() && $retry->sync_error !== null, 'Exactly one real worker validation failure without resend required');
        crAssert(! DB::table('jobs')->exists() && DB::table('failed_jobs')->count() === $order->payload['failed_jobs_before'], 'Queue must be consumed without new failed jobs');
    }
    crAssert(crCaptureCount() === $order->payload['capture_count_before'], 'Unexpected captured email appeared; stop and inspect');
    crAssert($order->fresh()->status === 'processing' && $retryOrder->fresh()->status === 'processing', 'Fictional order status must remain processing');
    $report += ['phase' => $phase, 'origin' => $origin, 'owner_id' => $owner->id, 'owner_email' => $owner->email,
        'deadline_order_id' => $order->id, 'deadline_delivery_id' => $delivery->id, 'ready_message' => CRP_READY,
        'retry_order_id' => $retryOrder->id, 'retry_delivery_id' => $retry->id,
        'deadline_status' => $delivery->status, 'deadline_send_attempts' => $delivery->send_attempts,
        'retry_status' => $retry->sync_status, 'retry_sync_attempts' => $retry->sync_attempts, 'retry_send_attempts' => $retry->send_attempts,
        'capture_count_unchanged' => true, 'elapsed_seconds' => round(crNow() - $started, 3),
        'limits' => ['No genuine Google credentials, email delivery or remote store request. Capture transport is enforced by the deployed staging application.',
            'Deadline drill exercises ordinary HTTPS controller/service after real order-lock contention. It does not simulate provider POST timeout or interrupt blocking DNS.',
            'Retry delivery is explicitly artificial capture-only metadata with invalid fulfillment proof; worker consumption is not completion/provider acceptance evidence.',
            'The HTTP caller must preserve its own status/latency/CSRF evidence. This CLI does not bypass authentication or expose a public probe route.']];
    $report['ok'] = true;
} catch (Throwable $exception) {
    $report += ['failed_stage' => $stage, 'exception_class' => get_class($exception),
        'assertion' => $exception instanceof CloudRuntimeAssertion ? $exception->getMessage() : 'Operation failed; inspect private logs without publishing credentials.'];
} finally {
    if ($locked && $db) {
        try {
            $db->rollBack();
        } catch (Throwable) {
            $db->disconnect();
        }
    }
    if (function_exists('pcntl_alarm')) {
        pcntl_alarm(0);
    }
    if (is_string($temporary) && is_file($temporary)) {
        unlink($temporary); // Only the exact private PDF created by this invocation.
    }
}
crReport($report);
exit($report['ok'] ? 0 : 1);
