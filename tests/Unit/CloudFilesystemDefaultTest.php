<?php

use App\Providers\AppServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Foundation\Cloud;

function cloudFilesystemFixture(mixed $override): Application
{
    $app = new Application(dirname(__DIR__, 2));
    $app->instance('config', new Repository([
        'filesystems' => [
            'default' => 'local',
            'application_default' => $override,
            'disks' => ['local' => ['driver' => 'local', 'root' => '/private-fixture']],
        ],
        'document_delivery' => ['disk' => 'documents_private'],
    ]));
    $previous = $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] ?? null;
    $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] = json_encode([[
        'disk' => 'documents_private', 'is_default' => true,
        'access_key_id' => 'fictional-key', 'access_key_secret' => 'fictional-secret',
        'bucket' => 'fictional-private-bucket', 'url' => null,
        'endpoint' => 'https://storage.example.invalid',
    ]], JSON_THROW_ON_ERROR);
    try {
        // Exercise the real framework injection that overwrites FILESYSTEM_DISK.
        Cloud::bootstrapperBootstrapped($app, LoadConfiguration::class);
    } finally {
        if ($previous === null) {
            unset($_SERVER['LARAVEL_CLOUD_DISK_CONFIG']);
        } else {
            $_SERVER['LARAVEL_CLOUD_DISK_CONFIG'] = $previous;
        }
    }

    return $app;
}

test('an explicit application default survives cloud injection without changing its document disk', function () {
    $app = cloudFilesystemFixture('local');
    expect($app['config']->get('filesystems.default'))->toBe('documents_private');
    $documentDisk = $app['config']->get('filesystems.disks.documents_private');

    (new AppServiceProvider($app))->register();

    expect((new FilesystemManager($app))->getDefaultDriver())->toBe('local')
        ->and($app['config']->get('document_delivery.disk'))->toBe('documents_private')
        ->and($app['config']->get('filesystems.disks.documents_private'))->toBe($documentDisk)
        ->and($documentDisk['driver'])->toBe('s3')
        ->and($documentDisk['url'])->toBeNull();
});

test('an unset application override preserves the cloud default', function ($override) {
    $app = cloudFilesystemFixture($override);

    (new AppServiceProvider($app))->register();

    expect((new FilesystemManager($app))->getDefaultDriver())->toBe('documents_private')
        ->and($app['config']->get('document_delivery.disk'))->toBe('documents_private');
})->with([null, '']);

test('an invalid application override refuses to replace the configured cloud default', function ($override) {
    $app = cloudFilesystemFixture($override);

    expect(fn () => (new AppServiceProvider($app))->register())->toThrow(InvalidArgumentException::class)
        ->and($app['config']->get('filesystems.default'))->toBe('documents_private');
})->with(['missing-disk', false]);
