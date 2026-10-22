<?php

// This harness is deliberately separate from public/index.php and the deployed application.
// A fixture created by local-fixture.php is the only permitted state source.

function wphubLocalApprovedRecipients(): array
{
    // Both addresses were explicitly approved by the user for fictional sample emails.
    // This is intentionally fixed; no CLI, request or manifest option can add recipients.
    return ['youssefbenamar755@gmail.com', 'benamar-y14@outlook.com'];
}

function wphubLocalFixture(string $directory): array
{
    $repository = dirname(__DIR__, 2);
    $root = realpath($directory);
    if (! $root || ! is_file($root.'/fixture.json')) {
        throw new RuntimeException('Select a fixture created with local-fixture.php --create.');
    }
    $state = json_decode(file_get_contents($root.'/fixture.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($state['format'] ?? null) !== 'wphub-local-gmail-fixture-v1'
        || ($state['origin'] ?? null) !== 'http://localhost:8799'
        || ($state['recipient'] ?? null) !== 'youssefbenamar755@gmail.com'
        || ! in_array($state['transport'] ?? null, ['capture', 'gmail'], true)
        || ! preg_match('/^base64:[A-Za-z0-9+\/]{43}=$/', $state['key'] ?? '')) {
        throw new RuntimeException('The fixture safety manifest is invalid.');
    }
    foreach ([$repository.'/public', $repository.'/storage/app/public'] as $public) {
        $public = str_replace('\\', '/', realpath($public) ?: $public);
        if (str_starts_with(strtolower(str_replace('\\', '/', $root)).'/', strtolower(rtrim($public, '/')).'/')) {
            throw new RuntimeException('Fixture state must be outside publicly served directories.');
        }
    }
    if (! is_file($root.'/fixture.sqlite')) {
        throw new RuntimeException('Fixture database is missing; refusing to create or select another database.');
    }
    // Remove inherited application configuration without reading or copying its values.
    foreach (glob($repository.'/config/*.php') as $file) {
        preg_match_all('/\benv\([\'\"]([A-Z][A-Z0-9_]*)[\'\"]/', file_get_contents($file), $matches);
        foreach ($matches[1] as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
    $environment = [
        'APP_NAME' => 'WP Hub — FICTIONAL LOCAL TEST', 'APP_ENV' => 'local', 'APP_DEBUG' => 'false',
        'APP_KEY' => $state['key'], 'APP_PREVIOUS_KEYS' => '', 'APP_URL' => $state['origin'],
        'APP_CONFIG_CACHE' => $root.'/never-config.php', 'APP_ROUTES_CACHE' => $root.'/never-routes.php',
        'APP_EVENTS_CACHE' => $root.'/never-events.php', 'APP_SERVICES_CACHE' => $root.'/cache/services.php',
        'APP_PACKAGES_CACHE' => $root.'/cache/packages.php',
        'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $root.'/fixture.sqlite', 'DB_URL' => '',
        'CACHE_STORE' => 'database', 'SESSION_DRIVER' => 'database', 'SESSION_DOMAIN' => 'null',
        'SESSION_COOKIE' => 'wphub_fictional_8799', 'SESSION_SECURE_COOKIE' => 'false',
        'SESSION_SAME_SITE' => 'lax', 'SESSION_ENCRYPT' => 'true', 'SESSION_EXPIRE_ON_CLOSE' => 'false',
        'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'database', 'BROADCAST_CONNECTION' => 'null',
        'BROADCAST_DRIVER' => 'null', 'FILESYSTEM_DISK' => 'local', 'WPHUB_DOCUMENT_DISK' => 'local',
        'WPHUB_VALIDATION_MODE' => 'true', 'WPHUB_VALIDATION_MAIL_TRANSPORT' => $state['transport'],
        'WPHUB_VALIDATION_RECIPIENTS' => implode(',', wphubLocalApprovedRecipients()), 'WPHUB_VALIDATION_STORE_ORIGINS' => '',
        'NIGHTWATCH_ENABLED' => 'false', 'NIGHTWATCH_TOKEN' => '', 'PULSE_ENABLED' => 'false',
        'TELESCOPE_ENABLED' => 'false', 'LOG_CHANNEL' => 'single', 'LOG_LEVEL' => 'warning',
        'AWS_ACCESS_KEY_ID' => '', 'AWS_SECRET_ACCESS_KEY' => '', 'AWS_SESSION_TOKEN' => '', 'AWS_BUCKET' => '',
        'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '', 'PUSHER_APP_ID' => '', 'PUSHER_APP_KEY' => '', 'PUSHER_APP_SECRET' => '',
    ];
    foreach ($environment as $name => $value) {
        putenv($name.'='.$value);
        $_ENV[$name] = $_SERVER[$name] = $value;
    }
    // Never load the repository .env or any repository configuration/route/event cache.
    foreach (['never-config.php', 'never-routes.php', 'never-events.php', 'absent.env'] as $forbidden) {
        if (file_exists($root.'/'.$forbidden)) {
            throw new RuntimeException('Unexpected cached configuration found in the isolated fixture.');
        }
    }
    require_once $repository.'/vendor/autoload.php';
    $app = require $repository.'/bootstrap/app.php';
    if (preg_match('/^[A-Za-z]:/', $root, $drive)) {
        $app->addAbsoluteCachePathPrefix($drive[0]);
    }
    $app->useEnvironmentPath($root)->loadEnvironmentFrom('absent.env');
    $app->useStoragePath($root.'/storage');
    $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['inertia.ssr.enabled' => false, 'filesystems.disks.local.serve' => false]);
    $app->make(\Illuminate\Foundation\Vite::class)->useHotFile($root.'/absent-vite-hot');
    $safety = $app->make(\App\Services\DocumentValidationSafety::class);
    if (! $safety->enabled() || config('app.env') !== 'local'
        || config('database.default') !== 'sqlite' || config('database.connections.sqlite.database') !== $root.'/fixture.sqlite'
        || config('document_delivery.validation.recipients') !== wphubLocalApprovedRecipients()
        || config('document_delivery.validation.store_origins') !== []
        || config('mail.default') !== 'array' || config('broadcasting.default') !== 'null') {
        throw new RuntimeException('Isolated runtime preflight failed.');
    }

    return [$app, $root, $state];
}
