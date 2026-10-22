<?php

// Local-only runner. Never loads the repository's .env, cached configuration or an existing database.
// Usage: php scripts/document-validation-smoke.php --output=C:/absolute/private/evidence/root

use App\Models\GmailConnection;
use App\Services\DocumentValidationSafety;
use App\Services\GmailClient;
use Dompdf\Dompdf;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Mime\Email;

require dirname(__DIR__).'/vendor/autoload.php';

class DocumentValidationForbiddenJob implements \Illuminate\Contracts\Queue\ShouldQueue
{
    public function handle(): void
    {
        throw new RuntimeException('An unrelated queued job reached its handler.');
    }
}

$options = getopt('', ['output:']);
$output = realpath((string) ($options['output'] ?? ''));
if (! $output || ! is_dir($output)) {
    fwrite(STDERR, "Provide an existing private output directory with --output.\n");
    exit(2);
}
foreach ([dirname(__DIR__).'/public', dirname(__DIR__).'/storage/app/public'] as $publicRoot) {
    $root = str_replace('\\', '/', realpath($publicRoot) ?: $publicRoot);
    $target = str_replace('\\', '/', $output);
    if (str_starts_with(strtolower($target).'/', strtolower(rtrim($root, '/')).'/')) {
        throw new RuntimeException('The validation output directory must not be publicly served.');
    }
}
$run = $output.'/wphub-validation-'.bin2hex(random_bytes(8));
if (! mkdir($run, 0700) || ! mkdir($run.'/storage/framework/views', 0700, true) || ! mkdir($run.'/storage/framework/cache', 0700, true)) {
    throw new RuntimeException('Cannot create fresh isolated fixture paths.');
}
file_put_contents($run.'/fixture.sqlite', '');
$environment = [
    'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
    'APP_URL' => 'http://127.0.0.1', 'APP_CONFIG_CACHE' => $run.'/absent-config.php', 'APP_ROUTES_CACHE' => $run.'/absent-routes.php',
    'APP_EVENTS_CACHE' => $run.'/absent-events.php', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $run.'/fixture.sqlite',
    'DB_URL' => '', 'DB_HOST' => '', 'DB_USERNAME' => '', 'DB_PASSWORD' => '',
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'BROADCAST_CONNECTION' => 'null', 'BROADCAST_DRIVER' => 'null', 'FILESYSTEM_DISK' => 'local', 'WPHUB_DOCUMENT_DISK' => 'local',
    'WPHUB_VALIDATION_MODE' => 'true', 'WPHUB_VALIDATION_MAIL_TRANSPORT' => 'capture',
    'WPHUB_VALIDATION_RECIPIENTS' => 'fixture-recipient@example.invalid', 'WPHUB_VALIDATION_STORE_ORIGINS' => '',
    'NIGHTWATCH_ENABLED' => 'false', 'PULSE_ENABLED' => 'false', 'TELESCOPE_ENABLED' => 'false',
    'AWS_ACCESS_KEY_ID' => '', 'AWS_SECRET_ACCESS_KEY' => '', 'AWS_SESSION_TOKEN' => '', 'AWS_BUCKET' => '',
    'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '', 'PUSHER_APP_ID' => '', 'PUSHER_APP_KEY' => '', 'PUSHER_APP_SECRET' => '',
];
foreach ($environment as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $_SERVER[$key] = $value;
}
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->useEnvironmentPath($run)->loadEnvironmentFrom('absent.env');
$app->useStoragePath($run.'/storage');
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
if (config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $run.'/fixture.sqlite'
    || ! app(DocumentValidationSafety::class)->capture() || config('document_delivery.validation.store_origins') !== []) {
    throw new RuntimeException('The isolated environment failed preflight.');
}
$result = $kernel->call('migrate', ['--force' => true]);
if ($result !== 0) {
    throw new RuntimeException('Fresh fixture migrations failed: '.$kernel->output());
}
$blocked = [];
foreach (['https://api.stripe.com/v1/charges', 'https://api.brevo.com/v3/smtp/email', 'https://production.example/wp-json/wc/v3/orders/1', 'https://gmail.googleapis.com/gmail/v1/users/me/messages/send'] as $url) {
    try {
        Http::connectTimeout(1)->timeout(1)->post($url);
        throw new RuntimeException('Outbound guard did not reject a forbidden endpoint.');
    } catch (RuntimeException $exception) {
        if (! str_contains($exception->getMessage(), 'Validation blocked')) {
            throw $exception;
        }
        $blocked[] = parse_url($url, PHP_URL_HOST);
    }
}
$pdf = new Dompdf;
$pdf->set_option('isRemoteEnabled', false);
$pdf->loadHtml('<h1>Fictional validation fixture</h1><p>Not a reservation or travel document.</p>');
$pdf->render();
$bytes = $pdf->output();
$mime = (new Email)->from('fixture-brand@example.invalid')->to('fixture-recipient@example.invalid')->subject('[LOCAL CAPTURE] Fictional validation')
    ->text('Fictional sample only. The PDF is attached.')->html('<h1>Fictional fixture</h1><p>The sample PDF is attached. No email was sent.</p>')
    ->attach($bytes, 'fictional-sample.pdf', 'application/pdf')->toString();
$capture = app(GmailClient::class)->send(new GmailConnection, $mime);
Storage::disk('local')->put('validation-fixture/fictional-sample.pdf', $bytes, ['visibility' => 'private']);
$httpKernel = app(\Illuminate\Contracts\Http\Kernel::class);
$webhookResponse = $httpKernel->handle(\Illuminate\Http\Request::create('/api/v1/webhooks/woocommerce/fictional', 'POST'));
$privateResponse = $httpKernel->handle(\Illuminate\Http\Request::create('/storage/validation-fixture/fictional-sample.pdf'));
if ($webhookResponse->getStatusCode() !== 503 || ! in_array($privateResponse->getStatusCode(), [403, 404], true)) {
    throw new RuntimeException('The isolation or private-file HTTP check failed.');
}
try {
    app(\Illuminate\Contracts\Bus\Dispatcher::class)->dispatch(new DocumentValidationForbiddenJob);
    throw new RuntimeException('An unrelated queued job was not blocked.');
} catch (RuntimeException $exception) {
    if (! str_contains($exception->getMessage(), 'Validation blocked an unrelated')) {
        throw $exception;
    }
}
$report = [
    'status' => 'isolated_local_capture_verified', 'run_directory' => $run,
    'laravel' => $app->version(), 'php' => PHP_VERSION, 'database' => 'new SQLite; no copied data',
    'repository_env_loaded' => false, 'production_config_cache_loaded' => false, 'external_requests_transmitted' => 0,
    'blocked_hosts' => $blocked, 'allowed_store_origins' => [], 'allowlisted_recipient' => 'fixture-recipient@example.invalid',
    'websites' => DB::table('websites')->count(), 'orders' => DB::table('wc_orders')->count(), 'jobs' => DB::table('jobs')->count(),
    'capture_path' => config('document_delivery.validation.capture_path').'/'.$capture.'.eml',
    'pdf_path' => Storage::disk('local')->path('validation-fixture/fictional-sample.pdf'), 'pdf_sha256' => hash('sha256', $bytes),
    'real_gmail_verified' => false, 'hosting_deployment_persistence_verified' => false,
    'incoming_webhook_http_status' => $webhookResponse->getStatusCode(), 'unauthorized_private_file_http_status' => $privateResponse->getStatusCode(),
    'unrelated_job_blocked_before_handle' => true,
];
file_put_contents($run.'/report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
