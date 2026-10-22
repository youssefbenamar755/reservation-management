<?php

// Scoped local-only draft setup. Default --plan is SQLite read-only; --apply is explicit.
// Never sends mail, refreshes OAuth, selects another database, creates orders or pairs stores.
// Public brand metadata is copied; integration URLs use .invalid and credentials stay absent.

if (PHP_SAPI !== 'cli' || array_diff(array_slice($argv, 1), ['--plan', '--apply', '--inspect'])
    || count(array_slice($argv, 1)) > 1) {
    fwrite(STDERR, "Use this fixed-scope CLI with --plan, --apply or --inspect.\n");
    exit(2);
}

require __DIR__.'/local-fixture-bootstrap.php';

try {
    $fixtureId = 'wphub-local-gmail-4483c123ccd53998';
    $base = dirname(__DIR__, 4).'/outputs';
    $output = $base.'/email-documents-release/site-setup';
    [$app, $root, $state] = wphubLocalFixture($base.'/'.$fixtureId);
    if (basename($root) !== $fixtureId || ($state['transport'] ?? null) !== 'capture'
        || config('document_delivery.validation.mail_transport') !== 'capture'
        || config('document_delivery.validation.store_origins') !== []) {
        throw new RuntimeException('The exact isolated capture fixture is required.');
    }
    \Illuminate\Support\Facades\Http::globalRequestMiddleware(static function () {
        throw new RuntimeException('All outbound requests are forbidden in this setup command.');
    });
    $apply = in_array('--apply', $argv, true);
    $inspect = in_array('--inspect', $argv, true);
    if (! $apply) {
        \Illuminate\Support\Facades\DB::connection()->getPdo()->exec('PRAGMA query_only = ON');
    }

    $registryBytes = file_get_contents($base.'/email-documents-release/registry.json');
    $registry = json_decode($registryBytes, true, flags: JSON_THROW_ON_ERROR);
    $manifest = json_decode(file_get_contents($output.'/setup-manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    if (! hash_equals($manifest['registry_sha256'] ?? '', hash('sha256', $registryBytes))
        || array_column($registry['websites'] ?? [], 'id') !== [8, 9, 10, 11, 12, 13]) {
        throw new RuntimeException('The reviewed six-site registry is required.');
    }
    $actor = \App\Models\User::where('email', 'operator@fixture.example.invalid')->sole();
    $originalSite = \App\Models\Website::where('slug', 'fictional-validation-only')->sole();
    if (! $actor->is_admin || \App\Models\User::count() !== 1 || (int) $originalSite->user_id !== (int) $actor->id
        || $originalSite->base_url !== 'https://fixture.example.invalid' || $originalSite->status !== 'paused') {
        throw new RuntimeException('The fictional operator and original website must remain unchanged.');
    }
    \Illuminate\Support\Facades\Auth::setUser($actor);
    $connection = \App\Models\GmailConnection::where('user_id', $actor->id)->where('email', 'visabookinghelp@gmail.com')->sole();
    $gmail = app(\App\Services\GmailClient::class);
    if (\App\Models\GmailConnection::count() !== 1 || ! $gmail->isConnected($connection)) {
        throw new RuntimeException('The existing approved fixture Gmail connection is required.');
    }
    $profiles = app(\App\Services\WebsiteDocumentProfiles::class);
    $renderer = app(\App\Services\BrandedDocumentRenderer::class);
    $controller = app(\App\Http\Controllers\Settings\DocumentSettingsController::class);
    $validate = new ReflectionMethod($controller, 'validated');
    $expectedSlugs = array_map(static fn ($id) => 'local-brand-draft-source-'.$id, [8, 9, 10, 11, 12, 13]);
    $credentialColumns = ['wc_consumer_key', 'wc_consumer_secret', 'wc_webhook_secret', 'ff_api_key', 'ff_username', 'ff_app_password', 'webhook_secret'];
    $assertScope = static function () use ($originalSite, $actor, $expectedSlugs, $credentialColumns): void {
        $sites = \App\Models\Website::query()->select(array_merge(['id', 'slug', 'user_id', 'base_url', 'status'], $credentialColumns))->get();
        if (! in_array($sites->count(), [1, 7], true) || \App\Models\WcOrder::count() !== 1
            || \App\Models\DocumentTestSend::count() !== 4 || \Illuminate\Support\Facades\DB::table('jobs')->count() !== 0
            || \App\Models\WebsiteDocumentProfile::count() !== $sites->count()
            || \App\Models\WebsiteDocumentProfile::where('workflow_enabled', true)->exists()) {
            throw new RuntimeException('Unexpected local fixture row counts or activation.');
        }
        foreach ($sites as $site) {
            if ((int) $site->user_id !== (int) $actor->id || $site->status !== 'paused'
                || ((int) $site->id !== (int) $originalSite->id && ! in_array($site->slug, $expectedSlugs, true))) {
                throw new RuntimeException('Unexpected website in the local fixture.');
            }
            $expectedOrigin = (int) $site->id === (int) $originalSite->id
                ? 'https://fixture.example.invalid' : 'https://brand-'.substr($site->slug, strlen('local-brand-draft-source-')).'.example.invalid';
            if ($site->base_url !== $expectedOrigin) {
                throw new RuntimeException('An integration origin is outside the .invalid fixture scope.');
            }
            foreach ($credentialColumns as $column) {
                // Raw check avoids decrypting any unexpected integration value.
                if ($site->getRawOriginal($column) !== null && $site->getRawOriginal($column) !== '') {
                    throw new RuntimeException('Integration credentials are forbidden in brand drafts.');
                }
            }
        }
        $order = \App\Models\WcOrder::query()->select(['website_id', 'wp_order_id', 'status', 'customer_email'])->sole();
        if ((int) $order->website_id !== (int) $originalSite->id || (int) $order->wp_order_id !== 900000001
            || $order->status !== 'processing' || $order->customer_email !== 'youssefbenamar755@gmail.com') {
            throw new RuntimeException('The original fictional order must remain unchanged.');
        }
    };
    $assertScope();
    $original = static fn () => hash('sha256', json_encode([
        \Illuminate\Support\Facades\DB::table('wc_orders')->where('website_id', $originalSite->id)->get()->toArray(),
        \Illuminate\Support\Facades\DB::table('website_document_profiles')->where('website_id', $originalSite->id)->get()->toArray(),
        \Illuminate\Support\Facades\DB::table('document_test_sends')->orderBy('id')->get()->toArray(),
    ], JSON_THROW_ON_ERROR));
    $before = $original();
    $planned = [];
    foreach ($registry['websites'] as $source) {
        $draft = json_decode(file_get_contents($output.'/website-'.$source['id'].'-disabled-draft.json'), true, flags: JSON_THROW_ON_ERROR);
        $data = $draft['configuration'];
        if (($draft['website_id'] ?? null) !== $source['id'] || $data['workflow_enabled'] !== false
            || $data['gmail_connection_id'] !== null || $data['sender_email'] !== $source['sender']
            || $data['brand_name'] !== $source['brand_name'] || $data['logo_url'] !== $source['logo_url']
            || $data['accent_color'] !== $source['accent_color'] || $data['website_url'] !== $source['url']
            || $data['reply_to'] !== $source['reply_to'] || $data['support_email'] !== $source['reply_to']
            || $data['service_label'] !== 'Documents' || $data['default_language'] !== 'fr'
            || $data['required_services'] !== ($source['sender'] ? ['All documents purchased in this order / Tous les documents achetés pour cette commande'] : [])) {
            throw new RuntimeException('Draft values do not match the reviewed local setup scope.');
        }
        if ($source['sender']) {
            if (! $profiles->hasAlias($connection, $source['sender'])) {
                throw new RuntimeException('A known professional sender is absent from the approved fixture account.');
            }
            $data['gmail_connection_id'] = $connection->id;
        }
        $slug = 'local-brand-draft-source-'.$source['id'];
        $site = \App\Models\Website::where('slug', $slug)->first();
        $existing = $site ? \App\Models\WebsiteDocumentProfile::where('website_id', $site->id)->sole() : null;
        $data['config_version'] = $existing ? (int) $existing->config_version : 0;
        $data = $validate->invoke($controller, $data, $renderer);
        $projection = $site ?? new \App\Models\Website(['user_id' => $actor->id]);
        if ($data['gmail_connection_id'] && ! $profiles->allowedConnections($projection, $actor, $existing)->contains('id', $connection->id)) {
            throw new RuntimeException('The fixture actor cannot assign this Gmail connection.');
        }
        $planned[] = ['source_id' => $source['id'], 'slug' => $slug, 'name' => '[LOCAL DRAFT] '.$source['brand_name'],
            'base_url' => 'https://brand-'.$source['id'].'.example.invalid', 'existing' => $site, 'data' => $data];
    }

    $created = 0;
    if ($apply && \App\Models\Website::count() === 1) {
        \Illuminate\Support\Facades\DB::transaction(function () use ($planned, $actor, $controller, $profiles, $renderer, $assertScope, $original, $before, &$created) {
            $assertScope();
            foreach ($planned as $item) {
                $site = \App\Models\Website::create(['user_id' => $actor->id, 'name' => $item['name'], 'slug' => $item['slug'],
                    'base_url' => $item['base_url'], 'status' => 'paused', 'timezone' => 'UTC']);
                $request = \Illuminate\Http\Request::create('/settings/email-documents/'.$site->id, 'PUT', $item['data'], server: ['HTTP_ACCEPT' => 'application/json']);
                $request->setUserResolver(static fn () => $actor);
                // Uses actual authorization, account-selection, validation, versioning and audit behavior.
                $controller->update($request, $site, $profiles, $renderer);
                $created++;
            }
            $assertScope();
            if (! hash_equals($before, $original())) {
                throw new RuntimeException('Original fictional order, profile or send evidence changed; roll back.');
            }
        });
    }
    $rows = [];
    foreach ($planned as $item) {
        $site = \App\Models\Website::where('slug', $item['slug'])->first();
        $profile = $site ? \App\Models\WebsiteDocumentProfile::where('website_id', $site->id)->sole() : null;
        if ($profile) {
            foreach ($item['data'] as $field => $value) {
                if ($field === 'config_version') {
                    continue;
                }
                if ($profile->$field !== $value) {
                    throw new RuntimeException('A saved draft differs; preserve manual edits and review instead of overwriting.');
                }
            }
        }
        $rows[] = ['source_website_id' => $item['source_id'], 'local_website_id' => $site?->id,
            'local_name' => $item['name'], 'integration_origin' => $item['base_url'], 'status' => 'paused',
            'brand_name' => $item['data']['brand_name'], 'identity_website_url' => $item['data']['website_url'],
            'sender_email' => $item['data']['sender_email'], 'reply_to' => $item['data']['reply_to'],
            'gmail_assigned' => $item['data']['gmail_connection_id'] !== null,
            'sender_in_fixture_aliases' => $item['data']['sender_email'] ? $profiles->hasAlias($connection, $item['data']['sender_email']) : false,
            'service_label' => $item['data']['service_label'], 'required_services' => $item['data']['required_services'],
            'default_language' => 'fr', 'workflow_enabled' => false,
            'saved' => $profile !== null, 'readiness' => $profile ? $profiles->readiness($profile) : null,
            'canada_intentionally_deferred' => $item['source_id'] === 13,
        ];
    }
    $assertScope();
    if (! hash_equals($before, $original())) {
        throw new RuntimeException('Original fixture evidence unexpectedly changed.');
    }
    $report = ['observed_at' => gmdate(DATE_ATOM), 'fixture_id' => $fixtureId,
        'mode' => $apply ? 'apply' : ($inspect ? 'inspect' : 'read-only-plan'),
        'transport' => 'capture', 'store_origins' => [], 'emails_sent' => 0, 'network_requests' => 0,
        'new_website_rows' => $created, 'new_profile_rows' => $created, 'normal_profile_audit_events' => $created,
        'original_fixture_order_profile_and_tests_unchanged' => true, 'test_send_count' => 4,
        'website_count' => \App\Models\Website::count(), 'enabled_profiles' => 0, 'jobs' => 0,
        'proposal_status' => 'Local disabled drafts; owner review and real staged store verification remain required.',
        'profiles' => $rows];
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
} catch (Throwable $exception) {
    // Keep exception details private: provider objects can contain sensitive state.
    fwrite(STDERR, 'Local draft setup stopped safely ('.get_class($exception)."). No private values printed.\n");
    exit(1);
}
