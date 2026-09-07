<?php

use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Website;
use App\Services\WebhookRecovery;
use App\Support\WebhookFailureSummary;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Carbon::setTestNow('2026-09-07 12:00:00');
    CarbonImmutable::setTestNow('2026-09-07 12:00:00');
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->website = healthWebsite($this->owner, 'Alpha');
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function healthWebsite(User $user, string $name): Website
{
    return Website::create([
        'user_id' => $user->id, 'name' => $name, 'slug' => strtolower($name), 'status' => 'active',
        'base_url' => 'https://'.strtolower($name).'.example', 'wc_webhook_secret' => 'private-integration-key',
        'last_webhook_at' => '2026-09-07 11:00:00', 'last_sync_at' => '2026-09-07 10:00:00', 'wc_orders_synced_at' => '2026-09-07 09:00:00',
    ]);
}

function healthEvent(Website $website, array $values = []): WebhookEvent
{
    return WebhookEvent::create(array_replace([
        'website_id' => $website->id, 'source' => 'woocommerce', 'topic' => 'order.created', 'external_id' => '42',
        'status' => 'failed', 'signature_valid' => true, 'received_at' => '2026-09-07 11:00:00',
        'payload' => ['id' => 42, 'email' => 'private-customer@example.test', 'secret' => 'private-payload-key'],
        'error_message' => 'SQLSTATE private-customer@example.test password=private-error-key',
    ], $values));
}

function healthAttempt(WebhookEvent $event, User $user, array $values = []): int
{
    return DB::table('webhook_retry_attempts')->insertGetId(array_replace([
        'webhook_event_id' => $event->id, 'user_id' => $user->id, 'status' => 'failed',
        'requested_at' => '2026-09-07 11:01:00', 'started_at' => '2026-09-07 11:01:01', 'finished_at' => '2026-09-07 11:01:02',
        'result_message' => 'SQLSTATE password=private-attempt-key private-customer@example.test',
        'created_at' => now(), 'updated_at' => now(),
    ], $values));
}

test('website health renders canonical filters and scoped website choices', function () {
    healthWebsite(User::factory()->create(), 'Foreign');
    healthEvent($this->website);
    $this->actingAs($this->owner)->get(route('website-health.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('WebsiteHealth/Index', false)
            ->where('filters', ['website_id' => null, 'status' => 'failed', 'source' => 'all', 'range' => '24h'])
            ->has('websites', 1)->where('websites.0.name', 'Alpha')->has('health.events.data', 1)
            ->where('health.timezone', 'UTC')->where('health.checked_at', '2026-09-07T12:00:00+00:00'));
    Http::assertNothingSent();
});

test('health totals use website scope while events use all filters and fixed rolling receipt windows', function () {
    $second = healthWebsite($this->owner, 'Beta');
    $foreign = healthWebsite(User::factory()->create(), 'Foreign');
    healthEvent($this->website, ['received_at' => '2026-09-06 12:00:00']);
    healthEvent($this->website, ['received_at' => '2026-09-06 11:59:59']);
    healthEvent($this->website, ['source' => 'fluentforms', 'topic' => 'form.submitted']);
    healthEvent($this->website, ['status' => 'queued', 'received_at' => '2026-08-01 10:00:00']);
    healthEvent($this->website, ['status' => 'processed', 'received_at' => '2026-08-01 10:00:00', 'processed_at' => '2026-09-07 11:00:00']);
    healthEvent($this->website, ['status' => 'processed', 'processed_at' => '2026-09-05 11:00:00']);
    healthEvent($second);
    healthEvent($foreign);

    $this->actingAs($this->owner)->getJson(route('website-health.index', ['website_id' => $this->website->id]))->assertOk()
        ->assertJsonPath('health.events.total', 2)->assertJsonCount(1, 'health.sites')
        ->assertJsonPath('health.summary', ['failed_recent' => 2, 'failed_total' => 3, 'queued' => 1, 'processed_recent' => 1, 'oldest_queued_at' => '2026-08-01T10:00:00+00:00'])
        ->assertJsonPath('health.sites.0.last_processed_at', '2026-09-07T11:00:00+00:00');
    $this->getJson(route('website-health.index', ['website_id' => $this->website->id, 'status' => 'processed', 'source' => 'woocommerce', 'range' => 'all']))
        ->assertOk()->assertJsonPath('health.events.total', 2)->assertJsonPath('health.summary.failed_total', 3);
    $this->getJson(route('website-health.index', ['website_id' => $this->website->id, 'source' => 'fluentforms']))
        ->assertOk()->assertJsonPath('health.events.total', 1)->assertJsonPath('health.summary.queued', 1);
    $this->getJson(route('website-health.index'))->assertOk()->assertJsonPath('health.summary.failed_total', 4);
});

test('health event date ranges include exact lower boundary and exclude future receipts', function (string $range, string $boundary) {
    healthEvent($this->website, ['received_at' => $boundary]);
    healthEvent($this->website, ['received_at' => CarbonImmutable::parse($boundary)->subSecond()]);
    healthEvent($this->website, ['received_at' => '2026-09-07 12:00:00']);
    healthEvent($this->website, ['received_at' => '2026-09-07 12:00:01']);
    $this->actingAs($this->owner)->getJson(route('website-health.index', ['range' => $range]))->assertOk()->assertJsonPath('health.events.total', 2);
})->with([['24h', '2026-09-06 12:00:00'], ['7d', '2026-08-31 12:00:00'], ['30d', '2026-08-08 12:00:00']]);

test('health pagination is stable and list queries are bounded without loading payloads', function () {
    foreach (range(1, 20) as $id) {
        $latest = healthEvent($this->website, ['external_id' => (string) $id]);
    }
    healthAttempt($latest, $this->owner);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->actingAs($this->owner)->getJson(route('website-health.index', ['range' => '7d', 'source' => 'woocommerce']))->assertOk();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    $response->assertJsonCount(15, 'health.events.data')->assertJsonPath('health.events.total', 20)
        ->assertJsonPath('health.events.data.0.id', $latest->id)->assertJsonPath('health.events.data.0.attempts_count', 1)
        ->assertJsonPath('health.events.per_page', 15)->assertJsonMissingPath('health.events.data.0.payload')
        ->assertJsonMissingPath('health.events.data.0.error_message')->assertJsonMissingPath('health.sites.0.wc_webhook_secret');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($response->json('health.events.next_page_url'))->toContain('range=7d', 'source=woocommerce');
    $reads = array_values(array_filter($queries, fn ($query) => preg_match('/^select/i', $query['query'])));
    expect(count($reads))->toBeLessThanOrEqual(5); // Four health queries plus the shared unread notification count.
    $healthReads = array_filter($reads, fn ($query) => preg_match('/\b(webhook_events|websites)\b/', $query['query']));
    expect(count($healthReads))->toBe(4);
    foreach ($reads as $query) {
        expect(strtolower($query['query']))->not->toContain('payload', 'select *');
    }
    $this->getJson(route('website-health.index', ['page' => 2]))->assertOk()->assertJsonCount(5, 'health.events.data')->assertJsonPath('health.events.data.0.external_id', '5');
    Http::assertNothingSent();
});

test('health retry accepts owner and admin requests with a safe durable attempt response', function (bool $admin) {
    Bus::fake();
    $event = healthEvent($this->website);
    $actor = $admin ? User::factory()->create(['is_admin' => true]) : $this->owner;
    $response = $this->actingAs($actor)->postJson(route('website-health.events.retry', $event))->assertStatus(202)
        ->assertJsonPath('attempt.status', 'queued')->assertJsonPath('attempt.requested_by', $actor->name)
        ->assertJsonPath('attempt.result_message', 'Retry queued.');
    expect($response->getContent())->not->toContain('private-', $actor->email);
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    $this->assertDatabaseHas('webhook_retry_attempts', ['webhook_event_id' => $event->id, 'user_id' => $actor->id, 'status' => 'queued']);
    $this->postJson(route('website-health.events.retry', $event))->assertUnprocessable()->assertJsonValidationErrors('retry');
    expect(DB::table('webhook_retry_attempts')->where('webhook_event_id', $event->id)->count())->toBe(1);
})->with([false, true]);

test('health retry limits each authenticated user to six requests per minute', function () {
    Bus::fake();
    $this->actingAs($this->owner);
    foreach (range(1, 6) as $number) {
        $this->postJson(route('website-health.events.retry', healthEvent($this->website)))->assertStatus(202);
    }
    $this->postJson(route('website-health.events.retry', healthEvent($this->website)))->assertStatus(429);
    expect(DB::table('webhook_retry_attempts')->count())->toBe(6);
});

test('health filters reject malformed values and unowned sites cannot be selected', function (array $filters) {
    $this->actingAs($this->owner)->getJson(route('website-health.index', $filters))->assertUnprocessable();
})->with([
    [['website_id' => 0]], [['website_id' => ['1']]], [['status' => 'unknown']], [['source' => 'other']],
    [['range' => 'yesterday']], [['page' => 0]], [['page' => 1000001]],
]);

test('health endpoints require authentication and enforce owner or admin access', function () {
    $foreign = healthWebsite(User::factory()->create(), 'Foreign');
    $event = healthEvent($foreign);
    $this->getJson(route('website-health.index'))->assertUnauthorized();
    $this->getJson(route('website-health.events.show', $event))->assertUnauthorized();
    $this->postJson(route('website-health.events.retry', $event))->assertUnauthorized();
    $this->actingAs($this->owner)->getJson(route('website-health.index', ['website_id' => $foreign->id]))->assertForbidden();
    $this->getJson(route('website-health.index', ['website_id' => 999999]))->assertForbidden();
    $this->getJson(route('website-health.events.show', $event))->assertForbidden();
    $this->postJson(route('website-health.events.retry', $event))->assertForbidden();
    expect(DB::table('webhook_retry_attempts')->count())->toBe(0);
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->getJson(route('website-health.index', ['website_id' => $foreign->id]))->assertOk()->assertJsonPath('health.events.total', 1);
    $this->getJson(route('website-health.events.show', $event))->assertOk()->assertJsonPath('event.website.id', $foreign->id);
});

test('health detail exposes safe eligibility and at most twenty sanitized attempts with requester names', function () {
    $event = healthEvent($this->website, ['topic' => 'private-topic@example.test', 'external_id' => 'private-id@example.test']);
    foreach (range(1, 25) as $id) {
        $lastAttempt = healthAttempt($event, $this->owner);
    }
    $this->mock(WebhookRecovery::class, function ($mock) {
        $mock->shouldReceive('eligibility')->once()->andReturn(['can_retry' => true, 'retry_reason' => null]);
    });
    $response = $this->actingAs($this->owner)->getJson(route('website-health.events.show', $event))->assertOk()
        ->assertJsonPath('event.can_retry', true)->assertJsonPath('event.retry_reason', null)->assertJsonPath('event.signature_valid', true)
        ->assertJsonPath('event.topic', 'unknown')->assertJsonPath('event.external_id', null)->assertJsonPath('event.attempts_count', 25)
        ->assertJsonCount(20, 'attempts')->assertJsonPath('attempts.0.id', $lastAttempt)->assertJsonPath('attempts.0.requested_by', $this->owner->name)
        ->assertJsonMissingPath('event.payload')->assertJsonMissingPath('event.error_message');
    expect($response->getContent())->not->toContain('private-', $this->owner->email, 'SQLSTATE');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
});

test('empty health scope returns complete zero states without other owners metadata', function () {
    healthEvent($this->website);
    $this->actingAs(User::factory()->create())->getJson(route('website-health.index'))->assertOk()
        ->assertJsonPath('health.summary', ['failed_recent' => 0, 'failed_total' => 0, 'queued' => 0, 'processed_recent' => 0, 'oldest_queued_at' => null])
        ->assertJsonPath('health.sites', [])->assertJsonPath('health.events.total', 0)->assertJsonPath('health.events.data', []);
});

test('webhook public failure classifier provides fixed categories without raw details', function (?string $error, string $expected) {
    expect(WebhookFailureSummary::summarize($error))->toContain($expected)->not->toContain('secret@example.test');
})->with([
    ['Missing form_id or entry_id secret@example.test', 'identifiers are missing'],
    ['Request timeout secret@example.test', 'timed out'],
    ['SQLSTATE secret@example.test', 'database operation'],
    ['cURL error 7 secret@example.test', 'service connection'],
    ['Invalid JSON payload secret@example.test', 'data format'],
    ['Unexpected secret@example.test', 'ask an administrator'],
    [null, 'ask an administrator'],
]);
