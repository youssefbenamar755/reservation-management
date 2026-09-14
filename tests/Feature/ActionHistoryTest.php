<?php

use App\Models\ActionHistoryEvent;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\WebhookEvent;
use App\Models\WebhookRetryAttempt;
use App\Models\Website;
use App\Services\ActionHistoryRecorder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    $this->travelTo(now()->setDate(2026, 9, 13)->setTime(12, 0));
    Http::preventStrayRequests();
    $this->actor = User::factory()->create();
    $this->site = historySite($this->actor);
    $this->order = WcOrder::create(['website_id' => $this->site->id, 'wp_order_id' => 4038, 'status' => 'processing', 'total' => 20, 'currency' => 'USD', 'payload' => ['private' => 'secret-payload']]);
});

function historySite(User $owner): Website
{
    return Website::create(['user_id' => $owner->id, 'name' => 'History fixture', 'slug' => (string) Str::uuid(), 'base_url' => 'https://history.example.test']);
}

function historyEvent(User $actor, Website $site, array $values = []): ActionHistoryEvent
{
    return ActionHistoryEvent::create(array_replace(['actor_id' => $actor->id, 'website_id' => $site->id, 'kind' => 'order_status', 'outcome' => 'succeeded', 'occurred_at' => now(), 'finished_at' => now(), 'details' => ['from' => 'processing', 'requested' => 'completed', 'confirmed' => 'completed', 'reason' => 'confirmed']], $values));
}

function historyRetry(User $actor, Website $site, string $status = 'succeeded'): WebhookRetryAttempt
{
    $webhook = WebhookEvent::create(['website_id' => $site->id, 'source' => 'woocommerce', 'topic' => 'order.created', 'status' => 'processed', 'external_id' => '4038', 'signature_valid' => true, 'received_at' => now()->subHour(), 'payload' => ['private' => 'secret-payload']]);

    return WebhookRetryAttempt::create(['webhook_event_id' => $webhook->id, 'user_id' => $actor->id, 'status' => $status, 'requested_at' => now()->subMinute(), 'finished_at' => now(), 'result_message' => 'Private exception with secret-access-token']);
}

test('history requires login and reads metadata without provider calls or payload exposure', function () {
    $this->getJson('/settings/action-history')->assertUnauthorized();
    historyEvent($this->actor, $this->site, ['reference' => '4038', 'order_id' => $this->order->id, 'details' => ['from' => 'processing', 'requested' => 'completed', 'confirmed' => 'completed', 'reason' => 'confirmed', 'private' => 'private-message']]);
    historyRetry($this->actor, $this->site);
    $response = $this->actingAs($this->actor)->getJson('/settings/action-history')->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->assertJsonPath('history.total', 2)
        ->assertJsonPath('history.data.0.action_url', '/orders/'.$this->order->id)
        ->assertJsonPath('history.data.0.changes.confirmed', 'completed');
    expect($response->getContent())->not->toContain('private-message', 'secret-payload', 'secret-access-token', 'email_verified_at', 'password');
    $this->get('/settings/action-history')->assertInertia(fn (Assert $page) => $page->component('ActionHistory/Index')->has('history.data', 2));
    Http::assertNothingSent();
});

test('current website access scopes history and actor options while email and alert actions stay personal', function () {
    $outsider = User::factory()->create();
    $foreignSite = historySite($outsider);
    $admin = User::factory()->create(['is_admin' => true]);
    historyEvent($outsider, $foreignSite);
    historyEvent($admin, $this->site);
    historyRetry($admin, $this->site);
    foreach (['email_send', 'email_record', 'alert_snoozed', 'alert_resumed'] as $kind) {
        historyEvent($admin, $this->site, ['kind' => $kind]);
        historyEvent($this->actor, $this->site, ['kind' => $kind]);
    }
    $this->actingAs($this->actor)->getJson('/settings/action-history')->assertOk()->assertJsonPath('history.total', 6)->assertJsonCount(2, 'people')->assertJsonCount(1, 'websites');
    $this->getJson('/settings/action-history?website_id='.$foreignSite->id)->assertForbidden();
    $this->getJson('/settings/action-history?actor_id='.$outsider->id)->assertOk()->assertJsonPath('history.total', 0);
    $this->actingAs($admin)->getJson('/settings/action-history')->assertOk()->assertJsonPath('history.total', 7);
    $this->actingAs($admin)->getJson('/settings/action-history?category=email&actor_id='.$this->actor->id)->assertOk()->assertJsonPath('history.total', 0);
    $this->site->update(['user_id' => $outsider->id]);
    $this->actingAs($this->actor)->getJson('/settings/action-history')->assertOk()->assertJsonPath('history.total', 0)->assertJsonCount(0, 'people');
});

test('order links and history do not follow an order transferred to another website', function () {
    $other = historySite(User::factory()->create());
    historyEvent($this->actor, $this->site, ['order_id' => $this->order->id, 'reference' => '4038']);
    $this->order->update(['website_id' => $other->id]);
    $this->actingAs($this->actor)->getJson('/settings/action-history')->assertOk()->assertJsonPath('history.total', 1)->assertJsonPath('history.data.0.action_url', null);
    $this->getJson('/settings/action-history?order_id='.$this->order->id)->assertNotFound();
    $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson('/settings/action-history?order_id='.$this->order->id)->assertOk()->assertJsonPath('history.total', 0);
});

test('per order history avoids ambiguous external webhook ids and applies filters to every page and summary', function () {
    historyEvent($this->actor, $this->site, ['order_id' => $this->order->id, 'reference' => '4038']);
    historyEvent($this->actor, $this->site, ['order_id' => $this->order->id, 'kind' => 'email_send', 'reference' => '4038', 'outcome' => 'failed']);
    historyEvent($this->actor, $this->site, ['reference' => '4038']);
    historyRetry($this->actor, $this->site);
    $this->actingAs($this->actor)->getJson('/settings/action-history?order_id='.$this->order->id)->assertOk()->assertJsonPath('history.total', 2)->assertJsonPath('orderContext.number', 4038);
    $this->getJson('/settings/action-history?category=email&outcome=failed&search=%234038&website_id='.$this->site->id)->assertOk()->assertJsonPath('history.summary', ['total' => 1, 'succeeded' => 0, 'attention' => 1, 'pending' => 0]);
    $this->getJson('/settings/action-history?order_id=999999')->assertNotFound();
});

test('date range is inclusive and pagination stays deterministic across mixed sources', function () {
    $ids = [];
    for ($i = 0; $i < 26; $i++) {
        $ids[] = historyEvent($this->actor, $this->site, ['occurred_at' => now()->startOfDay()])->id;
    }
    historyEvent($this->actor, $this->site, ['occurred_at' => now()->subDay()->endOfDay()]);
    historyEvent($this->actor, $this->site, ['occurred_at' => now()->addDay()->startOfDay()]);
    historyRetry($this->actor, $this->site);
    $url = '/settings/action-history?start_date=2026-09-13&end_date=2026-09-13';
    $this->actingAs($this->actor)->getJson($url)->assertOk()->assertJsonCount(25, 'history.data')->assertJsonPath('history.total', 27)->assertJsonPath('history.last_page', 2);
    $this->getJson($url.'&page=99')->assertOk()->assertJsonPath('history.current_page', 2)->assertJsonCount(2, 'history.data')->assertJsonPath('history.data.0.id', 'event:'.$ids[1])->assertJsonPath('history.data.1.id', 'event:'.$ids[0]);
});

test('abandoned requests become unconfirmed without rewriting history or treating queued retries as failed', function () {
    $old = historyEvent($this->actor, $this->site, ['outcome' => 'pending', 'occurred_at' => now()->subMinutes(6), 'finished_at' => null, 'details' => []]);
    historyEvent($this->actor, $this->site, ['outcome' => 'pending', 'finished_at' => null, 'details' => []]);
    historyRetry($this->actor, $this->site, 'queued')->update(['requested_at' => now()->subHour(), 'finished_at' => null]);
    $this->actingAs($this->actor)->getJson('/settings/action-history')->assertOk()->assertJsonPath('history.summary', ['total' => 3, 'succeeded' => 0, 'attention' => 1, 'pending' => 2]);
    $this->getJson('/settings/action-history?outcome=uncertain')->assertOk()->assertJsonPath('history.total', 1)->assertJsonPath('history.data.0.finished_at', null);
    expect($old->fresh()->outcome)->toBe('pending');
});

test('deleted orders and users leave readable site history with no broken order link', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    historyEvent($admin, $this->site, ['order_id' => $this->order->id]);
    $admin->delete();
    $this->order->delete();
    $this->actingAs($this->actor)->getJson('/settings/action-history')->assertOk()->assertJsonPath('history.data.0.actor.name', 'Former user')->assertJsonPath('history.data.0.action_url', null);
});

test('history rejects malformed filters', function (string $query) {
    $this->actingAs($this->actor)->getJson('/settings/action-history?'.$query)->assertUnprocessable();
})->with(['website_id[]=1', 'actor_id=-1', 'order_id=0', 'search=customer@example.test', 'search=%25', 'category=unknown', 'outcome=unknown', 'page=1000001', 'start_date=bad', 'start_date=2026-09-13&end_date=2026-09-12']);

test('provider result finalization failure keeps a durable unconfirmed request and never overwrites a terminal result', function () {
    $recorder = app(ActionHistoryRecorder::class);
    $event = $recorder->beginStatus($this->actor, $this->order, 'completed');
    DB::statement("CREATE TRIGGER history_result_failure BEFORE UPDATE ON action_history_events BEGIN SELECT RAISE(FAIL, 'synthetic history failure'); END");
    try {
        $recorder->finishStatus($event, 'succeeded', 'confirmed', 'completed');
        expect($event->fresh()->outcome)->toBe('pending');
    } finally {
        DB::statement('DROP TRIGGER history_result_failure');
    }
    $recorder->finishStatus($event, 'succeeded', 'confirmed', 'completed');
    $recorder->finishStatus($event, 'failed', 'rejected');
    expect($event->fresh()->outcome)->toBe('succeeded')->and($event->fresh()->details['confirmed'])->toBe('completed');
    Http::assertNothingSent();
});
