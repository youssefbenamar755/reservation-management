<?php

// Creates or inspects isolated local fixture state. Does not start a server or send any email.
// --create=C:/absolute/private/output/parent
// --fixture=C:/absolute/fixture --preflight
// --fixture=C:/absolute/fixture --transport=capture|gmail

require __DIR__.'/local-fixture-bootstrap.php';

$options = getopt('', ['create:', 'fixture:', 'preflight', 'transport:']);
if (isset($options['create'])) {
    $parent = realpath($options['create']);
    if (! $parent || ! is_dir($parent)) {
        throw new RuntimeException('Provide an existing private output parent directory.');
    }
    foreach ([dirname(__DIR__, 2).'/public', dirname(__DIR__, 2).'/storage/app/public'] as $public) {
        $public = str_replace('\\', '/', realpath($public) ?: $public);
        if (str_starts_with(strtolower(str_replace('\\', '/', $parent)).'/', strtolower(rtrim($public, '/')).'/')) {
            throw new RuntimeException('The fixture parent must be outside publicly served directories.');
        }
    }
    $root = $parent.'/wphub-local-gmail-'.bin2hex(random_bytes(8));
    if (! mkdir($root, 0700)) {
        throw new RuntimeException('Could not create a unique fixture directory.');
    }
    foreach (['cache', 'storage/framework/views', 'storage/framework/cache/data', 'storage/framework/sessions', 'storage/logs', 'storage/app/private'] as $subdirectory) {
        if (! mkdir($root.'/'.$subdirectory, 0700, true) && ! is_dir($root.'/'.$subdirectory)) {
            throw new RuntimeException('Could not create private fixture storage.');
        }
    }
    file_put_contents($root.'/fixture.sqlite', '');
    // Provider-class discovery metadata has no environment values or application data.
    // Copy only these arrays, not cached config/routes/events. This also avoids Windows sandbox
    // is_writable() false negatives in Laravel's discovery writer; actual private file I/O works.
    foreach (['services', 'packages'] as $metadata) {
        $source = dirname(__DIR__, 2).'/bootstrap/cache/'.$metadata.'.php';
        if (is_file($source)) {
            $classes = require $source;
            if (! is_array($classes)) {
                throw new RuntimeException('Unexpected provider discovery metadata.');
            }
            file_put_contents($root.'/cache/'.$metadata.'.php', '<?php return '.var_export($classes, true).';');
        }
    }
    $state = ['format' => 'wphub-local-gmail-fixture-v1', 'origin' => 'http://localhost:8799',
        'recipient' => 'youssefbenamar755@gmail.com', 'transport' => 'capture',
        'key' => 'base64:'.base64_encode(random_bytes(32)), 'created_at' => gmdate(DATE_ATOM)];
    file_put_contents($root.'/fixture.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    [$app] = wphubLocalFixture($root);
    $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
    if ($kernel->call('migrate', ['--force' => true]) !== 0) {
        throw new RuntimeException('Isolated migrations failed: '.$kernel->output());
    }
    $password = bin2hex(random_bytes(20));
    $user = \App\Models\User::create(['name' => 'Fictional validation operator', 'email' => 'operator@fixture.example.invalid',
        'password' => $password, 'is_admin' => true]);
    $user->forceFill(['email_verified_at' => now()])->save();
    $site = \App\Models\Website::create(['user_id' => $user->id, 'name' => 'FICTIONAL — WP Hub validation',
        'slug' => 'fictional-validation-only', 'base_url' => 'https://fixture.example.invalid', 'status' => 'paused', 'timezone' => 'UTC']);
    $order = \App\Models\WcOrder::withoutEvents(fn () => \App\Models\WcOrder::create([
        'website_id' => $site->id, 'wp_order_id' => 900000001, 'status' => 'processing', 'payment_status' => 'paid',
        'currency' => 'USD', 'total' => '0.00', 'customer_email' => $state['recipient'], 'customer_name' => 'Alex Fictional Sample',
        'created_at_wp' => now(), 'updated_at_wp' => now(), 'payload' => ['id' => 900000001, 'number' => 'SAMPLE-001',
            'status' => 'processing', 'currency' => 'USD', 'total' => '0.00', 'language' => 'en',
            'billing' => ['email' => $state['recipient'], 'first_name' => 'Alex', 'last_name' => 'Fictional Sample'],
            'line_items' => [['id' => 1, 'name' => 'Fictional sample — not a reservation', 'quantity' => 1, 'total' => '0.00']],
            'meta_data' => [], 'date_paid' => now()->toIso8601String(), 'transaction_id' => 'FICTIONAL-NO-PAYMENT'],
    ]));
    $templates = \App\Services\BrandedDocumentRenderer::defaults();
    foreach ($templates as &$languages) {
        foreach ($languages as $language => &$template) {
            $template['body'] = ($language === 'fr' ? 'TEST FICTIF — Aucune commande, réservation ou transaction réelle.' : 'FICTIONAL TEST — No real order, reservation or payment.')."\n\n".$template['body'];
        }
        unset($template);
    }
    unset($languages);
    \App\Models\WebsiteDocumentProfile::create(['website_id' => $site->id, 'configured_by_user_id' => $user->id,
        'brand_name' => 'FICTIONAL WP Hub validation', 'logo_url' => 'https://fixture.example.invalid/fictional-logo.png',
        'primary_color' => '#253040', 'accent_color' => '#52657D', 'background_color' => '#F4F6F8', 'text_color' => '#253040',
        'support_email' => 'support@fixture.example.invalid', 'website_url' => 'https://fixture.example.invalid',
        'footer' => 'Fictional local validation only. No real reservation, travel document or purchase.',
        'sender_name' => 'FICTIONAL WP Hub validation', 'reply_to' => 'support@fixture.example.invalid',
        'default_language' => 'en', 'service_label' => 'fictional sample', 'required_services' => ['flight'],
        'templates' => $templates, 'workflow_enabled' => false, 'config_version' => 1, 'store_pairing_key' => (string) \Illuminate\Support\Str::uuid(),
        'store_capability' => [],
    ]);
    file_put_contents($root.'/login-private.json', json_encode(['url' => $state['origin'].'/login',
        'email' => $user->email, 'password' => $password], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    unset($password);
    $options['fixture'] = $root;
} else {
    [$app, $root, $state] = wphubLocalFixture((string) ($options['fixture'] ?? ''));
}

if (isset($options['transport'])) {
    if (! in_array($options['transport'], ['capture', 'gmail'], true)) {
        throw new RuntimeException('Only capture or Gmail transport is permitted.');
    }
    $state['transport'] = $options['transport'];
    file_put_contents($root.'/fixture.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    echo json_encode(['transport_saved' => $state['transport'], 'recipient_allowlist' => wphubLocalApprovedRecipients(),
        'note' => 'New HTTP requests read this setting. No email was sent. Re-run --preflight separately.'], JSON_PRETTY_PRINT)."\n";
    exit;
}

$report = ['fixture_path' => $root, 'origin' => $state['origin'], 'callback' => $state['origin'].'/settings/email/callback',
    'transport' => $state['transport'], 'recipient_allowlist' => wphubLocalApprovedRecipients(), 'store_origins' => [],
    'environment' => app()->environment(), 'database' => 'fresh fixture SQLite', 'repository_env_loaded' => false,
    'repository_cached_config_loaded' => false, 'gmail_application_configured' => app(\App\Services\GmailClient::class)->configured(),
    'discovery_metadata_only' => 'isolated copies of package/provider class lists; no config, routes or events cache',
    'gmail_connections' => \App\Models\GmailConnection::count(), 'websites' => \App\Models\Website::count(), 'orders' => \App\Models\WcOrder::count(),
    'jobs' => \Illuminate\Support\Facades\DB::table('jobs')->count(), 'document_profiles_enabled' => \App\Models\WebsiteDocumentProfile::where('workflow_enabled', true)->count(),
    'login_credentials_file' => $root.'/login-private.json', 'private_storage' => $root.'/storage/app/private',
    'ssr_enabled' => config('inertia.ssr.enabled'), 'session_same_site' => config('session.same_site'),
    'session_secure' => config('session.secure'), 'session_domain' => config('session.domain'),
    'production_data_copied' => false, 'emails_sent_by_setup' => 0];

if (isset($options['preflight']) || isset($options['create'])) {
    $safety = app(\App\Services\DocumentValidationSafety::class);
    $blocked = [];
    // Exercise the real request guard directly: do not create a network request in this preflight.
    foreach (['https://api.stripe.com/v1/charges', 'https://api.brevo.com/v3/smtp/email',
        'https://fixture.example.invalid/wp-json/wc/v3/orders/1'] as $url) {
        try {
            $safety->guardRequest(new \GuzzleHttp\Psr7\Request('POST', $url));
            throw new RuntimeException('The outbound guard accepted a forbidden service.');
        } catch (RuntimeException $exception) {
            if (! str_contains($exception->getMessage(), 'Validation blocked')) {
                throw $exception;
            }
            $blocked[] = parse_url($url, PHP_URL_HOST);
        }
    }
    foreach (wphubLocalApprovedRecipients() as $recipient) {
        $safety->assertRecipients((new \Symfony\Component\Mime\Email)->from('fictional@example.invalid')
            ->to($recipient)->subject('Fixture guard preflight')->text('Not sent')->toString());
    }
    $report['approved_recipients_checked'] = wphubLocalApprovedRecipients();
    try {
        $safety->assertRecipients((new \Symfony\Component\Mime\Email)->from('fictional@example.invalid')
            ->to('unapproved@example.invalid')->subject('Rejected fixture')->text('Not sent')->toString());
        throw new RuntimeException('An unapproved recipient passed the validation guard.');
    } catch (\App\Exceptions\GmailDeliveryException $exception) {
        $report['unapproved_recipient_blocked'] = true;
    }
    $report['blocked_service_hosts'] = $blocked;
    $report['network_calls_in_preflight'] = 0;
    $report['callback_route_present'] = \Illuminate\Support\Facades\Route::has('email-settings.callback');
    $report['callback_requires_auth'] = in_array('auth', \Illuminate\Support\Facades\Route::getRoutes()->getByName('email-settings.callback')->gatherMiddleware(), true);
    $report['client_build_present'] = is_file(dirname(__DIR__, 2).'/public/build/manifest.json');
    $report['preflight'] = 'passed';
}
file_put_contents($root.'/preflight.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
