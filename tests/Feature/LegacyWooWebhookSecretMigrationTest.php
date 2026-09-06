<?php

use App\Models\User;
use App\Models\Website;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function legacyWooSecretWebsite(?string $rawSecret): Website
{
    $website = Website::create([
        'user_id' => User::factory()->create()->id,
        'name' => 'Legacy webhook fixture',
        'slug' => 'legacy-webhook-'.uniqid(),
        'base_url' => 'https://legacy.example.test',
        'status' => 'active',
    ]);
    DB::table('websites')->where('id', $website->id)->update(['wc_webhook_secret' => $rawSecret]);

    return $website->fresh();
}

function legacyWooSecretMigration(): object
{
    return require database_path('migrations/2026_09_06_000002_encrypt_legacy_woocommerce_webhook_secrets.php');
}

test('legacy generated webhook secrets are encrypted without rotating their value', function () {
    $secret = 'wc_'.str_repeat('Ab7', 13).'Z';
    $website = legacyWooSecretWebsite($secret);
    expect(fn () => $website->wc_webhook_secret)->toThrow(DecryptException::class);

    legacyWooSecretMigration()->up();

    $raw = DB::table('websites')->where('id', $website->id)->value('wc_webhook_secret');
    expect($raw)->not->toBe($secret)
        ->and(Crypt::decryptString($raw))->toBe($secret)
        ->and($website->fresh()->wc_webhook_secret)->toBe($secret);

    legacyWooSecretMigration()->up();
    legacyWooSecretMigration()->down();
    expect(DB::table('websites')->where('id', $website->id)->value('wc_webhook_secret'))->toBe($raw);
});

test('already encrypted webhook secrets remain byte for byte unchanged', function () {
    $secret = 'wc_'.str_repeat('A', 40);
    $ciphertext = Crypt::encryptString($secret);
    $website = legacyWooSecretWebsite($ciphertext);

    legacyWooSecretMigration()->up();

    expect(DB::table('websites')->where('id', $website->id)->value('wc_webhook_secret'))->toBe($ciphertext)
        ->and($website->fresh()->wc_webhook_secret)->toBe($secret);
});

test('existing WooCommerce webhook signatures still authenticate after the repair', function () {
    Queue::fake();
    $secret = 'wc_'.str_repeat('B', 40);
    $website = legacyWooSecretWebsite($secret);
    $body = json_encode(['id' => 321, 'status' => 'pending']);
    $signature = base64_encode(hash_hmac('sha256', $body, $secret, true));

    legacyWooSecretMigration()->up();

    $this->call('POST', '/api/v1/webhooks/woocommerce/'.$website->slug, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_WC_WEBHOOK_SIGNATURE' => $signature,
        'HTTP_X_WC_WEBHOOK_TOPIC' => 'order.created',
    ], $body)->assertOk();
    $this->assertDatabaseHas('webhook_events', ['website_id' => $website->id, 'signature_valid' => true]);
    Queue::assertPushed(\App\Jobs\ProcessWooWebhookEvent::class);
});

test('unrecognized webhook secret values are neither rewritten nor accepted as plaintext', function (string $unknown) {
    $website = legacyWooSecretWebsite($unknown);

    legacyWooSecretMigration()->up();

    expect(DB::table('websites')->where('id', $website->id)->value('wc_webhook_secret'))->toBe($unknown)
        ->and(fn () => $website->fresh()->wc_webhook_secret)->toThrow(DecryptException::class);
})->with([
    'unrecognized text' => ['not-an-encrypted-secret'],
    'incorrect legacy length' => ['wc_'.str_repeat('A', 39)],
    'extra trailing newline' => ['wc_'.str_repeat('A', 40)."\n"],
    'nonalphanumeric suffix' => ['wc_'.str_repeat('A', 39).'_'],
    'invalid encryption envelope' => [base64_encode('{"iv":"broken"}')],
]);

test('unconfigured webhook secrets remain null', function () {
    $website = legacyWooSecretWebsite(null);

    legacyWooSecretMigration()->up();

    expect($website->fresh()->wc_webhook_secret)->toBeNull();
});
