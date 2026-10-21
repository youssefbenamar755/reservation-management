<?php

// Explicit read-only production metadata projection. Never install/pair/update a store.
// APP_ENV=production php -d disable_functions=mail tools/validation/store-inventory-probe.php --read-only-production --expected-database=SCHEMA [--website-id=8]

use App\Models\Website;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

function siRequire(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function siText(mixed $value, int $limit = 180): ?string
{
    if (! is_string($value) && ! is_numeric($value)) {
        return null;
    }

    return mb_substr(preg_replace('/[\x00-\x1f\x7f]/', '', (string) $value), 0, $limit);
}

function siEmail(mixed $value): ?string
{
    return is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL) && ! preg_match('/[\r\n]/', $value) ? $value : null;
}

function siGet(Website $site, string $expectedHost, string $route, string $fields): array
{
    siRequire(in_array($route, ['system_status', 'settings/email', 'settings/email_customer_processing_order', 'settings/email_customer_completed_order'], true), 'Unapproved metadata route');
    siRequire(rtrim((string) $site->base_url, '/') === 'https://'.$expectedHost, 'Registry origin mismatch');
    $records = dns_get_record($expectedHost, DNS_A | DNS_AAAA);
    $addresses = array_values(array_unique(array_filter(array_map(static fn ($record) => $record['ip'] ?? $record['ipv6'] ?? null, $records ?: []))));
    siRequire($addresses !== [], 'Store DNS unavailable');
    foreach ($addresses as $address) {
        siRequire((bool) filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && ! str_starts_with(strtolower($address), '::ffff:'), 'Private or mixed DNS refused');
    }
    $address = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];
    // Read encrypted fields only after fixed registry checks. Values never enter output or files.
    $key = (string) $site->wc_consumer_key;
    $secret = (string) $site->wc_consumer_secret;
    siRequire($key !== '' && $secret !== '' && ! preg_match('/[\r\n:]/', $key) && ! preg_match('/[\r\n]/', $secret), 'Configured Woo credentials required');
    $body = '';
    $curl = curl_init('https://'.$expectedHost.'/wp-json/wc/v3/'.$route.'?_fields='.rawurlencode($fields));
    siRequire($curl !== false, 'cURL initialization failed');
    curl_setopt_array($curl, [
        CURLOPT_HTTPGET => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROXY => '', CURLOPT_RESOLVE => [$expectedHost.':443:'.$address],
        CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 15, CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_USERPWD => $key.':'.$secret,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'Cache-Control: no-cache'], CURLOPT_USERAGENT => 'WPHub-Approved-ReadOnly-Store-Inventory/1.0',
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > 2097152) {
                return 0;
            }
            $body .= $chunk;

            return strlen($chunk);
        },
    ]);
    $ok = curl_exec($curl);
    $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    $errno = curl_errno($curl);
    curl_close($curl);
    unset($key, $secret);
    siRequire($ok !== false && $errno === 0, 'Store metadata request failed');
    if ($status !== 200) {
        return ['available' => false, 'http_status' => $status];
    }
    $data = json_decode($body, true, flags: JSON_THROW_ON_ERROR);
    siRequire(is_array($data), 'Unexpected metadata response');

    return ['available' => true, 'http_status' => $status, 'data' => $data];
}

function siStatus(array $data): array
{
    $environment = (array) ($data['environment'] ?? []);
    $settings = (array) ($data['settings'] ?? []);
    $theme = (array) ($data['theme'] ?? []);
    $plugins = [];
    foreach ((array) ($data['active_plugins'] ?? []) as $plugin) {
        $plugins[] = ['name' => siText($plugin['name'] ?? null), 'version' => siText($plugin['version'] ?? null, 40), 'plugin' => siText($plugin['plugin'] ?? null)];
    }
    $overrides = [];
    foreach ((array) ($theme['overrides'] ?? []) as $override) {
        $file = str_replace('\\', '/', (string) ($override['file'] ?? ''));
        // Strip absolute server path; retain only a bounded relative theme/plugin path.
        $offset = strpos($file, 'wp-content/');
        $file = $offset === false ? basename($file) : substr($file, $offset);
        $overrides[] = ['file' => siText($file), 'version' => siText($override['version'] ?? null, 40), 'core_version' => siText($override['core_version'] ?? null, 40)];
    }
    $engines = [];
    $prefix = (string) ($data['database']['database_prefix'] ?? '');
    foreach (['wc_orders', 'wc_orders_meta', 'wc_order_addresses', 'wc_order_operational_data', 'posts', 'postmeta', 'comments', 'commentmeta'] as $suffix) {
        $engines[$suffix] = null;
        foreach ((array) ($data['database']['database_tables'] ?? []) as $group) {
            if ($prefix !== '' && isset($group[$prefix.$suffix]['engine'])) {
                $engines[$suffix] = siText($group[$prefix.$suffix]['engine'], 30);
            }
        }
    }
    $muPlugins = array_map(static fn ($item) => ['name' => siText($item['name'] ?? null), 'version' => siText($item['version'] ?? null, 40)], (array) ($data['dropins_mu_plugins']['mu_plugins'] ?? []));
    $dropins = array_map(static fn ($item) => siText($item['name'] ?? null), (array) ($data['dropins_mu_plugins']['dropins'] ?? []));

    return ['runtime' => ['wordpress' => siText($environment['wp_version'] ?? null, 40), 'woocommerce' => siText($environment['version'] ?? null, 40),
        'php' => siText($environment['php_version'] ?? null, 40), 'database' => siText($environment['mysql_version_string'] ?? $environment['mysql_version'] ?? null, 80),
        'language' => siText($environment['language'] ?? null, 20), 'web_server' => siText($environment['server_info'] ?? null, 60)],
        'hpos_enabled' => $settings['HPOS_enabled'] ?? null, 'hpos_sync_enabled' => $settings['HPOS_sync_enabled'] ?? null,
        'email_improvements_enabled' => in_array('email_improvements', (array) ($settings['enabled_features'] ?? []), true), 'table_engines' => $engines,
        'theme' => ['name' => siText($theme['name'] ?? null), 'version' => siText($theme['version'] ?? null, 40), 'parent_name' => siText($theme['parent_name'] ?? null),
            'parent_version' => siText($theme['parent_version'] ?? null, 40), 'has_outdated_templates' => $theme['has_outdated_templates'] ?? null, 'overrides' => $overrides],
        'active_plugins' => $plugins, 'mu_plugins' => $muPlugins, 'dropins' => $dropins];
}

function siSettings(array $items, bool $general): array
{
    $values = [];
    foreach ($items as $item) {
        if (isset($item['id'])) {
            $values[(string) $item['id']] = $item['value'] ?? null;
        }
    }
    if ($general) {
        return ['from_name' => siText($values['woocommerce_email_from_name'] ?? null), 'from_address' => siEmail($values['woocommerce_email_from_address'] ?? null),
            'reply_to_enabled' => ($values['woocommerce_email_reply_to_enabled'] ?? null) === 'yes',
            'reply_to_address' => siEmail($values['woocommerce_email_reply_to_address'] ?? null)];
    }

    return ['enabled' => $values['enabled'] ?? null, 'email_type' => siText($values['email_type'] ?? null, 20),
        'subject_customized' => filled($values['subject'] ?? null), 'heading_customized' => filled($values['heading'] ?? null),
        'additional_content_configured' => filled($values['additional_content'] ?? null), 'cc_configured' => filled($values['cc'] ?? null), 'bcc_configured' => filled($values['bcc'] ?? null)];
}

$stage = 'arguments';
$rows = [];
$transaction = false;
try {
    siRequire(PHP_SAPI === 'cli', 'CLI only');
    $options = getopt('', ['read-only-production', 'expected-database:', 'website-id:', 'help']);
    if (isset($options['help'])) {
        echo json_encode(['usage' => 'APP_ENV=production php -d disable_functions=mail tools/validation/store-inventory-probe.php --read-only-production --expected-database=SCHEMA [--website-id=8|9|12|13]',
            'scope' => 'Authenticated GET metadata only for the four fixed registered origins. Stored Woo credentials stay in-process. No site/order/customer writes or exports, no plugin installation, no email. Projection excludes raw responses, server paths, table prefixes/sizes and credentials.'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        exit(0);
    }
    siRequire(array_key_exists('read-only-production', $options) && getenv('APP_ENV') === 'production', 'Explicit production read-only invocation required');
    siRequire(in_array('mail', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true), 'Native mail must be disabled');
    $schema = (string) ($options['expected-database'] ?? '');
    siRequire((bool) preg_match('/^[A-Za-z0-9_]{1,64}$/D', $schema), 'Observed expected database schema required');
    siRequire(extension_loaded('curl'), 'cURL required');
    $sites = [8 => 'reservationpourvisa.com', 9 => 'pre-reservation.fr', 12 => 'confirmedtkt.com', 13 => 'prereservation.ca'];
    if (isset($options['website-id'])) {
        $id = (string) $options['website-id'];
        siRequire(in_array($id, ['8', '9', '12', '13'], true), 'Only remaining registered website IDs are allowed');
        $sites = [(int) $id => $sites[(int) $id]];
    }
    $stage = 'bootstrap';
    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    siRequire($app->environment('production') && rtrim((string) config('app.url'), '/') === 'https://wphub.website', 'Exact production WP Hub identity required');
    siRequire(! config('document_delivery.validation.enabled'), 'Production must not use document validation mode');
    Http::preventStrayRequests();
    Mail::fake();
    Bus::fake();
    $stage = 'read-only-database';
    siRequire(DB::connection()->getDriverName() === 'mysql' && DB::selectOne('select database() as name')->name === $schema, 'Database identity mismatch');
    siRequire(DB::transactionLevel() === 0, 'Existing transaction refused');
    DB::statement('SET TRANSACTION READ ONLY');
    DB::beginTransaction();
    $transaction = true;
    foreach ($sites as $id => $host) {
        $stage = 'website-'.$id;
        $site = Website::query()->select(['id', 'base_url', 'status', 'wc_consumer_key', 'wc_consumer_secret'])->findOrFail($id);
        siRequire(rtrim((string) $site->base_url, '/') === 'https://'.$host && $site->status === 'active', 'Fixed registered active website mismatch');
        $row = ['registry_id' => $id, 'url' => 'https://'.$host, 'read_only' => true, 'metadata' => []];
        foreach (['system_status' => 'environment,settings,theme,active_plugins,dropins_mu_plugins,database',
            'settings/email' => 'id,value', 'settings/email_customer_processing_order' => 'id,value', 'settings/email_customer_completed_order' => 'id,value'] as $route => $fields) {
            $response = siGet($site, $host, $route, $fields);
            $row['metadata'][$route] = ['available' => $response['available'], 'http_status' => $response['http_status']];
            if ($response['available']) {
                $row['metadata'][$route]['projection'] = $route === 'system_status' ? siStatus($response['data']) : siSettings($response['data'], $route === 'settings/email');
            }
        }
        $rows[] = $row;
        unset($site);
    }
    DB::rollBack();
    $transaction = false;
    echo json_encode(['checked_at_utc' => gmdate(DATE_ATOM), 'status' => 'read-only-inventory-complete', 'websites' => $rows,
        'limits' => ['Metadata only, not behavioral compatibility, real SMTP delivery or companion pairing proof.', 'System-status GET may run Woo built-in connectivity checks and refresh diagnostic transients; authenticated GETs may update API-key access timestamps. No operational setting or business record is changed.', 'Stored keys were used only in-process; raw responses and customer/order/form data are not exported.'],
        'explicit_hub_database_writes' => 0, 'explicit_store_mutations' => 0, 'mail_sent' => 0, 'plugins_installed' => 0], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    if ($transaction) {
        try {
            DB::rollBack();
        } catch (Throwable) {
        }
    }
    // No exception message/trace, HTTP body or URL query containing credentials may be printed.
    echo json_encode(['status' => 'refused-or-incomplete', 'stage' => $stage, 'exception_type' => get_class($exception), 'websites' => $rows], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
    exit(1);
}
