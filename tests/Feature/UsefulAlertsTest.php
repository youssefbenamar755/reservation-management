<?php

use App\Models\GmailConnection;
use App\Models\OrderEmailDelivery;
use App\Models\UsefulAlert;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\WebhookEvent;
use App\Models\Website;
use App\Services\UsefulAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false, 'broadcasting.default' => 'null']);
    $this->travelTo(now()->setDate(2026, 9, 10)->setTime(12, 0));
    Http::preventStrayRequests();
    $this->actor = User::factory()->create();
    $this->site = alertTestSite($this->actor);
});

function alertTestSite(User $user): Website
{
    return Website::create(['user_id' => $user->id, 'name' => 'Alerts fixture', 'slug' => (string) Str::uuid(), 'base_url' => 'https://alerts.example.test']);
}

function alertTestWebhook(Website $site, array $values = []): WebhookEvent
{
    return WebhookEvent::create(array_replace(['website_id' => $site->id, 'source' => 'woocommerce', 'topic' => 'order.created',
        'status' => 'failed', 'signature_valid' => true, 'received_at' => now()->subHour(), 'payload' => ['private' => 'secret-payload']], $values));
}

function alertTestOrder(Website $site, array $values = []): WcOrder
{
    return WcOrder::create(array_replace(['website_id' => $site->id, 'wp_order_id' => random_int(1, 9999999),
        'status' => 'processing', 'total' => 20, 'currency' => 'USD', 'created_at_wp' => now()->subDays(2),
        'customer_email' => 'private@example.test', 'payload' => ['private' => 'secret-payload']], $values));
}

function alertTestEmail(User $actor, WcOrder $order, array $values = []): OrderEmailDelivery
{
    $gmail = GmailConnection::firstOrCreate(['user_id' => $actor->id], ['email' => 'sender@example.test',
        'connection_key' => (string) Str::uuid(), 'app_fingerprint' => str_repeat('a', 64)]);

    return OrderEmailDelivery::create(array_replace(['user_id' => $actor->id, 'website_id' => $order->website_id,
        'wc_order_id' => $order->id, 'gmail_connection_id' => $gmail->id, 'connection_key' => $gmail->connection_key,
        'fingerprint' => hash('sha256', (string) Str::uuid()), 'status' => 'failed',
        'snapshot' => ['private' => 'private-message'], 'mime' => 'private-pdf-bytes', 'expires_at' => now()->addHour()], $values));
}

test('alert routes require authentication and GET never scans or contacts a provider', function () {
    $this->getJson('/alerts')->assertUnauthorized();
    $this->postJson('/alerts/refresh')->assertUnauthorized();
    $this->postJson('/alerts/1/snooze')->assertUnauthorized();
    alertTestWebhook($this->site);
    $this->actingAs($this->actor)->getJson('/alerts')->assertOk()->assertJsonPath('alertCenter.checked_at', null)
        ->assertJsonPath('alertCenter.total', 0)->assertHeader('Cache-Control', 'no-store, private');
    expect(UsefulAlert::count())->toBe(0)->and($this->actor->notifications()->count())->toBe(0);
    $this->get('/alerts')->assertInertia(fn (Assert $page) => $page->component('Alerts/Index'));
    Http::assertNothingSent();
});

test('completed scans create four grouped alert kinds without private payloads or external calls', function () {
    alertTestWebhook($this->site);
    alertTestWebhook($this->site, ['status' => 'queued']);
    $order = alertTestOrder($this->site);
    alertTestEmail($this->actor, $order);
    $this->actingAs($this->actor)->postJson('/alerts/refresh')->assertOk()
        ->assertJsonPath('alertCenter.summary', ['active' => 4, 'snoozed' => 0, 'resolved' => 0])
        ->assertJsonPath('alertCenter.thresholds', ['webhook_minutes' => 5, 'processing_hours' => 24]);
    $response = $this->getJson('/alerts');
    expect($response->getContent())->not->toContain('private@example.test', 'secret-payload', 'private-message', 'private-pdf-bytes', 'notification_id', 'episode_key');
    expect($this->actor->notifications()->count())->toBe(4);
    Http::assertNothingSent();
});

test('continuing issues update counts without duplicating notifications and recurrence creates one new episode', function () {
    $event = alertTestWebhook($this->site);
    $alerts = app(UsefulAlerts::class);
    $alerts->scan($this->actor);
    $row = UsefulAlert::first();
    $episode = $row->episode_key;
    $first = $row->first_detected_at;
    $this->travel(6)->minutes();
    $second = alertTestWebhook($this->site);
    $alerts->scan($this->actor);
    expect($row->fresh()->count)->toBe(2)->and($row->fresh()->first_detected_at->eq($first))->toBeTrue()
        ->and($this->actor->notifications()->count())->toBe(1);
    $event->update(['status' => 'processed']);
    $second->update(['status' => 'processed']);
    $alerts->scan($this->actor);
    expect($row->fresh()->resolved_at)->not->toBeNull()->and($this->actor->unreadNotifications()->count())->toBe(0);
    $event->update(['status' => 'failed']);
    $alerts->scan($this->actor);
    $alerts->scan($this->actor);
    expect(UsefulAlert::count())->toBe(1)->and($row->fresh()->episode_key)->not->toBe($episode)
        ->and($row->fresh()->resolved_at)->toBeNull()->and($this->actor->notifications()->count())->toBe(2);
});

test('snooze and resume do not resolve or duplicate an episode and expiry returns it to active', function () {
    alertTestWebhook($this->site);
    $alerts = app(UsefulAlerts::class);
    $alerts->scan($this->actor);
    $row = UsefulAlert::first();
    $this->actingAs($this->actor)->postJson('/alerts/'.$row->id.'/snooze')->assertOk();
    expect($row->fresh()->resolved_at)->toBeNull()->and($this->actor->unreadNotifications()->count())->toBe(0);
    $this->getJson('/alerts?status=snoozed')->assertOk()->assertJsonPath('alertCenter.total', 1);
    $this->postJson('/alerts/'.$row->id.'/resume')->assertOk();
    $alerts->scan($this->actor);
    expect($this->actor->notifications()->count())->toBe(1);
    $this->postJson('/alerts/'.$row->id.'/snooze')->assertOk();
    $this->travel(24)->hours();
    $this->getJson('/alerts')->assertOk()->assertJsonPath('alertCenter.total', 1);
    $alerts->scan($this->actor);
    expect($this->actor->notifications()->count())->toBe(1);
});

test('recurrence while snoozed waits until expiry to notify once', function () {
    $event = alertTestWebhook($this->site);
    $alerts = app(UsefulAlerts::class);
    $alerts->scan($this->actor);
    $row = UsefulAlert::first();
    $alerts->snooze($this->actor, $row->id, true);
    $event->update(['status' => 'processed']);
    $alerts->scan($this->actor);
    $event->update(['status' => 'failed']);
    $alerts->scan($this->actor);
    expect($this->actor->notifications()->count())->toBe(1)->and($row->fresh()->notification_id)->toBeNull();
    $this->travel(24)->hours();
    $alerts->scan($this->actor);
    $alerts->scan($this->actor);
    expect($this->actor->notifications()->count())->toBe(2);
});

test('only verified failures and queued events at the five minute boundary are detected', function () {
    alertTestWebhook($this->site, ['signature_valid' => false]);
    alertTestWebhook($this->site, ['status' => 'processed']);
    alertTestWebhook($this->site, ['status' => 'queued', 'received_at' => now()->subSeconds(299)]);
    alertTestWebhook($this->site, ['status' => 'queued', 'received_at' => now()->addHour()]);
    app(UsefulAlerts::class)->scan($this->actor);
    expect(UsefulAlert::count())->toBe(0);
    alertTestWebhook($this->site, ['status' => 'queued', 'received_at' => now()->subMinutes(5)]);
    app(UsefulAlerts::class)->scan($this->actor);
    expect(UsefulAlert::first()->kind)->toBe('webhook_stalled')->and(UsefulAlert::first()->count)->toBe(1);
});

test('processing age uses the order date including exact boundary and excludes other statuses unknown and future dates', function () {
    foreach ([now()->subHours(24), now()->subDays(2), now()->subHours(23), now()->addDay(), null] as $date) {
        alertTestOrder($this->site, ['created_at_wp' => $date]);
    }
    foreach (['completed', 'pending', 'on-hold', 'cancelled', 'refunded', 'failed'] as $status) {
        alertTestOrder($this->site, ['status' => $status]);
    }
    app(UsefulAlerts::class)->scan($this->actor);
    expect(UsefulAlert::first()->kind)->toBe('processing_aged')->and(UsefulAlert::first()->count)->toBe(2);
});

test('email attention follows latest actor send including completed orders and does not decrypt payloads', function () {
    $other = User::factory()->create();
    $completed = alertTestOrder($this->site, ['status' => 'completed']);
    $delivery = alertTestEmail($this->actor, $completed);
    // Corrupt ciphertext deliberately: scan and listing must never decrypt it.
    DB::table('order_email_deliveries')->where('id', $delivery->id)->update(['snapshot' => 'not-ciphertext', 'mime' => 'not-ciphertext']);
    alertTestEmail($other, $completed, ['status' => 'sent']);
    app(UsefulAlerts::class)->scan($this->actor);
    $this->actingAs($this->actor)->getJson('/alerts?kind=email_attention')->assertOk()->assertJsonPath('alertCenter.data.0.count', 1)
        ->assertJsonPath('alertCenter.data.0.examples.0.id', $completed->id);
    $this->travel(1)->seconds();
    alertTestEmail($this->actor, $completed, ['status' => 'sent']);
    app(UsefulAlerts::class)->scan($this->actor);
    $this->getJson('/alerts?kind=email_attention')->assertJsonPath('alertCenter.total', 0);
    expect($delivery->fresh()->getRawOriginal('snapshot'))->toBe('not-ciphertext');
});

test('email attention handles stale sends and deterministic equal timestamps', function () {
    foreach ([['failed', null], ['uncertain', null], ['sending', null], ['sending', now()->subSeconds(301)], ['sending', now()->subMinutes(5)], ['sent', null]] as [$status, $sending]) {
        alertTestEmail($this->actor, alertTestOrder($this->site, ['status' => 'completed']), ['status' => $status, 'sending_at' => $sending]);
    }
    $order = alertTestOrder($this->site, ['status' => 'completed']);
    alertTestEmail($this->actor, $order, ['id' => '00000000-0000-4000-8000-000000000001', 'status' => 'failed']);
    alertTestEmail($this->actor, $order, ['id' => '00000000-0000-4000-8000-000000000002', 'status' => 'sent']);
    app(UsefulAlerts::class)->scan($this->actor);
    expect(UsefulAlert::first()->count)->toBe(4);
});

test('users and admins only see and mutate their own alert records and current website access is enforced', function () {
    alertTestWebhook($this->site);
    $other = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $alerts = app(UsefulAlerts::class);
    $alerts->scan($this->actor);
    $row = UsefulAlert::first();
    $this->actingAs($other)->getJson('/alerts?website_id='.$this->site->id)->assertForbidden();
    $this->postJson('/alerts/refresh', ['website_id' => $this->site->id])->assertForbidden();
    $this->postJson('/alerts/'.$row->id.'/snooze')->assertNotFound();
    $this->actingAs($admin)->postJson('/alerts/'.$row->id.'/resume')->assertNotFound();
    $alerts->scan($admin);
    $this->getJson('/alerts')->assertJsonPath('alertCenter.total', 1);
    $this->site->update(['user_id' => $other->id]);
    $this->actingAs($this->actor)->getJson('/alerts')->assertJsonPath('alertCenter.total', 0);
    $this->postJson('/alerts/'.$row->id.'/snooze')->assertNotFound();
    $alerts->scan($this->actor);
    expect(UsefulAlert::find($row->id))->toBeNull()->and($this->actor->unreadNotifications()->count())->toBe(0);
});

test('email history never leaks to admins or another website after an order moves', function () {
    $order = alertTestOrder($this->site, ['status' => 'completed']);
    alertTestEmail($this->actor, $order);
    $admin = User::factory()->create(['is_admin' => true]);
    app(UsefulAlerts::class)->scan($admin);
    expect(UsefulAlert::where('user_id', $admin->id)->count())->toBe(0);
    $newSite = alertTestSite($this->actor);
    $order->update(['website_id' => $newSite->id]);
    app(UsefulAlerts::class)->scan($this->actor);
    expect(UsefulAlert::where('user_id', $this->actor->id)->count())->toBe(0);
});

test('examples are bounded to three safe order links and status totals respect website and category filters', function () {
    for ($i = 0; $i < 8; $i++) {
        alertTestEmail($this->actor, alertTestOrder($this->site));
    }
    alertTestWebhook(alertTestSite($this->actor));
    app(UsefulAlerts::class)->scan($this->actor);
    $row = UsefulAlert::where('kind', 'email_attention')->first();
    app(UsefulAlerts::class)->snooze($this->actor, $row->id, true);
    $this->actingAs($this->actor)->getJson('/alerts?kind=email_attention&website_id='.$this->site->id.'&status=all&page=999')
        ->assertOk()->assertJsonPath('alertCenter.summary', ['active' => 0, 'snoozed' => 1, 'resolved' => 0])
        ->assertJsonPath('alertCenter.current_page', 1)->assertJsonCount(3, 'alertCenter.data.0.examples');
});

test('resolved alerts reject snoozing and invalid filter values are rejected', function () {
    $event = alertTestWebhook($this->site);
    app(UsefulAlerts::class)->scan($this->actor);
    $row = UsefulAlert::first();
    $event->update(['status' => 'processed']);
    app(UsefulAlerts::class)->scan($this->actor);
    $this->actingAs($this->actor)->postJson('/alerts/'.$row->id.'/snooze')->assertStatus(409);
    $this->getJson('/alerts?status=bad&kind=bad&website_id=0&page=0')->assertUnprocessable()->assertJsonValidationErrors(['status', 'kind', 'website_id', 'page']);
});

test('scan failures roll back episode resolution and checked time', function () {
    $event = alertTestWebhook($this->site);
    $alerts = app(UsefulAlerts::class);
    $alerts->scan($this->actor);
    $row = UsefulAlert::first();
    $checked = DB::table('useful_alert_scan_states')->where('user_id', $this->actor->id)->value('checked_at');
    $event->update(['status' => 'processed']);
    $this->travel(6)->minutes();
    UsefulAlert::updating(function () {
        throw new RuntimeException('Synthetic write failure');
    });
    try {
        $alerts->scan($this->actor);
        $this->fail('Expected scan failure');
    } catch (RuntimeException $error) {
        expect($error->getMessage())->toBe('Synthetic write failure');
    } finally {
        UsefulAlert::flushEventListeners();
    }
    expect($row->fresh()->resolved_at)->toBeNull()->and($this->actor->unreadNotifications()->count())->toBe(1)
        ->and(DB::table('useful_alert_scan_states')->where('user_id', $this->actor->id)->value('checked_at'))->toBe($checked);
});

test('alert command completes stored record checks', function () {
    alertTestWebhook($this->site);
    $this->artisan('alerts:scan')->assertSuccessful();
    expect(UsefulAlert::count())->toBe(1);
});
