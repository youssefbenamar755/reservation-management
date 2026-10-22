<?php

// Credential-free public routing verification, only from an approved isolated Cloud stage.
// APP_ENV=staging php -d disable_functions=mail tools/validation/logo-geo-probe.php --expected-app-url=https://APP.laravel.cloud

use App\Services\DocumentValidationSafety;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;

function lgRequire(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function lgAddress(string $host): string
{
    lgRequire(in_array($host, ['wphub.website', 'www.cloudflare.com'], true), 'Unapproved public host');
    $records = dns_get_record($host, DNS_A | DNS_AAAA);
    $addresses = array_values(array_unique(array_filter(array_map(static fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null, $records ?: []))));
    lgRequire($addresses !== [], 'Public host did not resolve');
    foreach ($addresses as $address) {
        lgRequire((bool) filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && ! str_starts_with(strtolower($address), '::ffff:'), 'Non-public DNS response refused');
    }

    return str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
}

function lgRequest(string $host, string $method, string $path): array
{
    lgRequire(in_array($method, ['GET', 'HEAD', 'POST'], true) && str_starts_with($path, '/') && ! str_contains($path, '?'), 'Invalid probe request');
    $address = lgAddress($host);
    $body = '';
    $headers = [];
    $curl = curl_init('https://'.$host.$path);
    lgRequire($curl !== false, 'cURL initialization failed');
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROXY => '',
        CURLOPT_RESOLVE => [$host.':443:'.$address], CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 12,
        CURLOPT_HTTPHEADER => ['Accept: application/json,image/png,image/gif,text/plain;q=0.9', 'Cache-Control: no-cache'],
        CURLOPT_USERAGENT => 'WPHub-Approved-Public-Routing-Validation/1.0',
        CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                if (in_array(strtolower(trim($name)), ['content-type', 'server', 'cf-mitigated'], true)) {
                    $headers[strtolower(trim($name))] = trim($value);
                }
            }

            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 2097152) {
                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        },
    ]);
    if ($method === 'POST') {
        // No real slug, bearer/signature/token, order/form data or tracking identifier.
        curl_setopt($curl, CURLOPT_POSTFIELDS, '{}');
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept: application/json', 'Content-Type: application/json', 'Cache-Control: no-cache']);
    }
    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($curl);
    curl_close($curl);
    lgRequire($ok !== false && $errno === 0, 'Public request failed or response exceeded limit');
    $contentType = strtolower(explode(';', $headers['content-type'] ?? '')[0]);
    $cloudflareBlock = $status === 403 && $contentType === 'text/html'
        && (str_contains(strtolower($body), 'cloudflare') || ($headers['cf-mitigated'] ?? '') === 'challenge');

    return ['status' => $status, 'content_type' => $contentType, 'cloudflare_block' => $cloudflareBlock, 'body' => $body];
}

$stage = 'arguments';
$checks = [];
$location = null;
try {
    lgRequire(PHP_SAPI === 'cli', 'CLI only');
    $options = getopt('', ['expected-app-url:', 'help']);
    if (isset($options['help'])) {
        echo json_encode(['usage' => 'APP_ENV=staging php -d disable_functions=mail tools/validation/logo-geo-probe.php --expected-app-url=https://APP.laravel.cloud',
            'scope' => 'Public exact-logo GET/HEAD, geo-block negatives, invalid unauthenticated integration requests. No credentials, valid webhooks, orders, tracking events, emails or rule changes. Country and colo only; no IP output.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        exit(0);
    }
    $origin = rtrim((string) ($options['expected-app-url'] ?? ''), '/');
    lgRequire((bool) preg_match('#^https://[a-z0-9-]+\.laravel\.cloud$#D', $origin), 'Exact approved staging origin required');
    lgRequire(getenv('APP_ENV') === 'staging', 'Process environment must explicitly be staging');
    lgRequire(in_array('mail', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true), 'Native mail must be disabled');
    lgRequire(extension_loaded('curl'), 'cURL required');
    $stage = 'bootstrap';
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    lgRequire($app->environment('staging') && rtrim((string) config('app.url'), '/') === $origin && ! config('app.debug'), 'Staging application identity mismatch');
    $safety = app(DocumentValidationSafety::class);
    lgRequire($safety->enabled() && $safety->capture() && config('document_delivery.validation.store_origins') === [], 'Isolated capture with empty store allowlist required');
    Http::preventStrayRequests();

    $stage = 'source-location';
    $trace = lgRequest('www.cloudflare.com', 'GET', '/cdn-cgi/trace');
    lgRequire($trace['status'] === 200, 'Cloudflare source trace unavailable');
    preg_match('/^loc=([A-Z]{2})$/m', $trace['body'], $country);
    preg_match('/^colo=([A-Z]{3})$/m', $trace['body'], $colo);
    lgRequire(isset($country[1], $colo[1]) && $country[1] !== 'MA', 'Non-Moroccan source country must be established');
    $location = ['country' => $country[1], 'colo' => $colo[1], 'source' => 'https://www.cloudflare.com/cdn-cgi/trace'];
    unset($trace);

    $stage = 'logos';
    $names = ['pre-reservation', 'reservationvisa', 'dummytickets', 'confirmedtkt'];
    foreach ($names as $name) {
        $path = '/images/email-brands/'.$name.'.png';
        $expected = hash_file('sha256', dirname(__DIR__, 2).'/public'.$path);
        lgRequire(is_string($expected), 'Committed PNG source unavailable');
        foreach (['GET', 'HEAD'] as $method) {
            $response = lgRequest('wphub.website', $method, $path);
            $png = $method === 'HEAD' || str_starts_with($response['body'], "\x89PNG\r\n\x1a\n");
            $digest = $method === 'GET' ? hash('sha256', $response['body']) : null;
            $checks[] = ['kind' => 'public-logo', 'method' => $method, 'path' => $path, 'status' => $response['status'], 'content_type' => $response['content_type'],
                'sha256' => $digest, 'expected_sha256' => $expected, 'bytes' => $method === 'GET' ? strlen($response['body']) : null,
                'passed' => $response['status'] === 200 && $response['content_type'] === 'image/png' && $png && ($method === 'HEAD' || hash_equals($expected, $digest))];
        }
    }
    $stage = 'protected-and-negative-routes';
    foreach ([['GET', '/login'], ['GET', '/dashboard'], ['GET', '/settings/email'], ['GET', '/orders'],
        ['GET', '/images/email-brands/not-allowlisted.png'], ['GET', '/images/email-brands/pre-reservation.png/extra'],
        ['GET', '/images/email-brands/PRE-RESERVATION.png'], ['POST', '/images/email-brands/pre-reservation.png'],
        ['GET', '/storage/documents/not-a-real-document.pdf']] as [$method, $path]) {
        $response = lgRequest('wphub.website', $method, $path);
        $checks[] = ['kind' => 'geo-block-retained', 'method' => $method, 'path' => $path, 'status' => $response['status'],
            'content_type' => $response['content_type'], 'cloudflare_block' => $response['cloudflare_block'], 'passed' => $response['cloudflare_block']];
    }
    $stage = 'unauthenticated-integration-reachability';
    $slug = 'wphub-validation-nonexistent-'.bin2hex(random_bytes(12));
    foreach (['/api/v1/webhooks/woocommerce/'.$slug, '/api/v1/webhooks/fluentforms/'.$slug, '/api/marketing/events/2147483647'] as $path) {
        $response = lgRequest('wphub.website', 'POST', $path);
        $checks[] = ['kind' => 'integration-auth-rejection', 'method' => 'POST', 'path' => $path, 'status' => $response['status'],
            'content_type' => $response['content_type'], 'passed' => in_array($response['status'], [403, 404], true)
                && $response['content_type'] === 'application/json' && ! $response['cloudflare_block']];
    }
    $response = lgRequest('wphub.website', 'HEAD', '/api/email/opens/not-a-valid-token.gif');
    $checks[] = ['kind' => 'stateless-pixel-no-event', 'method' => 'HEAD', 'path' => '/api/email/opens/not-a-valid-token.gif',
        'status' => $response['status'], 'content_type' => $response['content_type'], 'passed' => $response['status'] === 200 && $response['content_type'] === 'image/gif'];
    $response = lgRequest('wphub.website', 'GET', '/up');
    $checks[] = ['kind' => 'health-exception', 'method' => 'GET', 'path' => '/up', 'status' => $response['status'], 'passed' => $response['status'] === 200];
    $failed = count(array_filter($checks, static fn ($check) => ! $check['passed']));
    echo json_encode(['checked_at_utc' => gmdate(DATE_ATOM), 'source_location' => $location, 'status' => $failed === 0 ? 'passed' : 'failed',
        'checks' => $checks, 'checks_count' => count($checks), 'failed_count' => $failed,
        'limits' => ['Public routing and source PNG equality only; not actual Gmail image-proxy rendering.', 'Invalid/no-secret integration requests prove routing/auth rejection, not order/form processing.', 'Moroccan browser access must be checked separately.'],
        'valid_webhooks_sent' => 0, 'real_mail_sent' => 0, 'rule_changes' => 0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
    exit($failed === 0 ? 0 : 1);
} catch (Throwable $exception) {
    // Never output cURL trace, IP addresses, response bodies, secrets or application stack.
    echo json_encode(['status' => 'refused-or-incomplete', 'stage' => $stage, 'exception_type' => get_class($exception), 'source_location' => $location, 'checks' => $checks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(1);
}
