<?php

use App\Models\FfSubmission;
use App\Models\GmailConnection;
use App\Models\OrderEmailDelivery;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    $this->travelTo(now()->setDate(2026, 9, 10)->setTime(12, 0));
    Http::preventStrayRequests();
    $this->actor = User::factory()->create();
    $this->site = Website::create(['user_id' => $this->actor->id, 'name' => 'Queue fixture', 'slug' => 'queue-fixture', 'base_url' => 'https://queue.example.test']);
    $this->gmail = GmailConnection::create(['user_id' => $this->actor->id, 'email' => 'sender@example.test', 'connection_key' => (string) Str::uuid(),
        'app_fingerprint' => str_repeat('a', 64), 'access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh']);
});

function workQueueOrder(Website $site, int $wpId, array $values = []): WcOrder
{
    return WcOrder::create(array_replace(['website_id' => $site->id, 'wp_order_id' => $wpId, 'status' => 'processing', 'currency' => 'USD', 'total' => 25,
        'customer_email' => 'customer@example.test', 'customer_name' => 'Queue customer', 'created_at_wp' => '2025-01-01 12:00:00',
        'payload' => ['private' => 'private-order-data']], $values));
}

function workQueueEmail(WcOrder $order, User $actor, GmailConnection $gmail, string $status, array $values = []): OrderEmailDelivery
{
    return OrderEmailDelivery::create(array_replace([
        'user_id' => $actor->id, 'wc_order_id' => $order->id, 'website_id' => $order->website_id, 'gmail_connection_id' => $gmail->id,
        'connection_key' => $gmail->connection_key, 'fingerprint' => hash('sha256', (string) Str::uuid()), 'status' => $status,
        'snapshot' => ['private' => 'private-message'], 'mime' => 'private-pdf-bytes', 'expires_at' => now()->addHour(),
        'sending_at' => $status === 'sending' ? now() : null, 'sent_at' => $status === 'sent' ? now() : null,
    ], $values));
}

test('work queue is authenticated scoped to active backlog across all dates and never contacts providers', function () {
    $this->get('/order-work-queue')->assertRedirect('/login');
    foreach (['processing', 'pending', 'on-hold', 'completed', 'failed', 'cancelled', 'refunded', 'checkout-draft'] as $index => $status) {
        workQueueOrder($this->site, $index + 1, ['status' => $status]);
    }
    $foreign = Website::create(['user_id' => User::factory()->create()->id, 'name' => 'Foreign', 'slug' => 'foreign', 'base_url' => 'https://foreign.example.test']);
    workQueueOrder($foreign, 1);
    $this->actingAs($this->actor)->getJson('/order-work-queue')->assertOk()->assertJsonCount(3, 'queue.data')->assertJsonPath('queue.total', 3)
        ->assertJsonPath('queue.summary', ['all' => 3, 'prepare' => 1, 'ready' => 0, 'sending' => 0, 'sent' => 0, 'attention' => 0, 'waiting' => 2])
        ->assertJsonPath('queue.timezone', 'UTC')->assertJsonMissingPath('queue.data.0.payload')->assertJsonMissingPath('queue.data.0.website.user_id')
        ->assertHeader('Cache-Control', 'no-store, private');
    $this->getJson('/order-work-queue?website_id='.$foreign->id)->assertForbidden();
    $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson('/order-work-queue?website_id='.$foreign->id)->assertOk()->assertJsonPath('queue.total', 1);
    Http::assertNothingSent();
});

test('work queue derives email precedence expiry and uncertain timestamps without mutating deliveries', function (string $woo, ?string $email, array $values, string $stage, ?string $effective) {
    $order = workQueueOrder($this->site, 1, ['status' => $woo]);
    foreach ($values as $key => $value) {
        if (is_int($value)) {
            $values[$key] = now()->addSeconds($value);
        }
    }
    $delivery = $email === null ? null : workQueueEmail($order, $this->actor, $this->gmail, $email, $values);
    $before = $delivery?->fresh()->getRawOriginal();
    $response = $this->actingAs($this->actor)->getJson('/order-work-queue')->assertOk()->assertJsonPath('queue.data.0.stage', $stage)
        ->assertJsonPath('queue.summary.'.$stage, 1)->assertJsonPath('queue.summary.all', 1);
    if ($effective === null) {
        $response->assertJsonPath('queue.data.0.email', null);
    } else {
        $response->assertJsonPath('queue.data.0.email.status', $effective)->assertJsonMissingPath('queue.data.0.email.snapshot')
            ->assertDontSee('private-pdf-bytes')->assertDontSee('private-message');
        expect($delivery->fresh()->getRawOriginal())->toBe($before);
    }
})->with([
    ['processing', null, [], 'prepare', null], ['pending', null, [], 'waiting', null],
    ['processing', 'prepared', [], 'ready', 'prepared'], ['processing', 'prepared', ['expires_at' => 0], 'prepare', 'expired'],
    ['processing', 'prepared', ['expires_at' => 1], 'ready', 'prepared'],
    ['processing', 'prepared', ['expires_at' => -1], 'prepare', 'expired'], ['processing', 'prepared', ['mime' => null], 'prepare', 'expired'],
    ['processing', 'expired', [], 'prepare', 'expired'], ['processing', 'failed', ['expires_at' => -1], 'attention', 'failed'],
    ['processing', 'uncertain', [], 'attention', 'uncertain'], ['processing', 'sending', ['sending_at' => -300], 'sending', 'sending'],
    ['processing', 'sending', ['sending_at' => -301], 'attention', 'uncertain'], ['processing', 'sending', ['sending_at' => null], 'attention', 'uncertain'],
    ['processing', 'sent', [], 'sent', 'sent'], ['pending', 'prepared', [], 'waiting', 'prepared'],
    ['on-hold', 'sent', [], 'waiting', 'sent'], ['pending', 'failed', [], 'attention', 'failed'],
]);

test('work queue uses latest created email then deterministic UUID tie with actor and current website isolation', function () {
    $order = workQueueOrder($this->site, 1);
    workQueueEmail($order, $this->actor, $this->gmail, 'sent', ['id' => '00000000-0000-4000-8000-000000000001', 'created_at' => now()->subHour()]);
    workQueueEmail($order, $this->actor, $this->gmail, 'prepared', ['id' => '00000000-0000-4000-8000-000000000003']);
    workQueueEmail($order, $this->actor, $this->gmail, 'failed', ['id' => '00000000-0000-4000-8000-000000000002']);
    $other = User::factory()->create();
    workQueueEmail($order, $other, $this->gmail, 'uncertain', ['created_at' => now()->addHour()]);
    $otherSite = Website::create(['user_id' => $this->actor->id, 'name' => 'Other', 'slug' => 'other', 'base_url' => 'https://other.example.test']);
    workQueueEmail($order, $this->actor, $this->gmail, 'failed', ['website_id' => $otherSite->id, 'created_at' => now()->addHours(2)]);
    $this->actingAs($this->actor)->getJson('/order-work-queue')->assertOk()->assertJsonPath('queue.data.0.stage', 'ready')->assertJsonPath('queue.summary.attention', 0);
    $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson('/order-work-queue')->assertOk()->assertJsonPath('queue.data.0.email', null)->assertJsonPath('queue.data.0.stage', 'prepare');
});

test('work queue counts precede stage filtering and pagination clamps after work leaves the backlog', function () {
    foreach (range(1, 28) as $id) {
        $order = workQueueOrder($this->site, $id);
        if ($id <= 2) {
            workQueueEmail($order, $this->actor, $this->gmail, 'sent');
        }
    }
    $this->actingAs($this->actor)->getJson('/order-work-queue?stage=prepare&page=2')->assertOk()->assertJsonCount(1, 'queue.data')
        ->assertJsonPath('queue.total', 26)->assertJsonPath('queue.summary.all', 28)->assertJsonPath('queue.summary.sent', 2)
        ->assertJsonPath('queue.current_page', 2)->assertJsonPath('queue.from', 26)->assertJsonPath('queue.to', 26)->assertJsonPath('queue.data.0.wp_order_id', 28);
    WcOrder::where('wp_order_id', 28)->update(['status' => 'completed']);
    $this->getJson('/order-work-queue?stage=prepare&page=2')->assertOk()->assertJsonPath('queue.current_page', 1)->assertJsonCount(25, 'queue.data');
    $this->getJson('/order-work-queue?sort=newest&per_page=50')->assertOk()->assertJsonPath('queue.data.0.wp_order_id', 27)->assertJsonPath('queue.per_page', 50);
});

test('work queue escaped literal search and website selection also scope summary counts', function (string $search) {
    workQueueOrder($this->site, 1, ['customer_name' => 'Exact '.$search.' value']);
    workQueueOrder($this->site, 2, ['customer_name' => 'Unrelated']);
    $this->actingAs($this->actor)->getJson('/order-work-queue?'.http_build_query(['website_id' => $this->site->id, 'search' => ' '.$search.' ']))
        ->assertOk()->assertJsonPath('queue.total', 1)->assertJsonPath('queue.summary.all', 1)->assertJsonPath('queue.data.0.wp_order_id', 1);
})->with(['%', '_', '!%_']);

test('work queue rejects unsupported and excessive filter inputs', function (array $filters) {
    $this->actingAs($this->actor)->getJson('/order-work-queue?'.http_build_query($filters))->assertUnprocessable();
    Http::assertNothingSent();
})->with([[['website_id' => 0]], [['stage' => 'completed']], [['sort' => 'total']], [['per_page' => 100]], [['page' => 1000001]], [['page' => 0]],
    [['search' => str_repeat('x', 201)]], [['search' => ['invalid']]], [['website_id' => ['invalid']]]]);

test('work queue Inertia defaults and row status capability use the existing policy', function () {
    workQueueOrder($this->site, 1);
    $this->partialMock(\App\Policies\WcOrderPolicy::class, fn ($mock) => $mock->shouldReceive('update')->once()->andReturn(false));
    $this->actingAs($this->actor)->get('/order-work-queue')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Orders/WorkQueue')
        ->where('filters', ['website_id' => null, 'search' => '', 'stage' => 'all', 'sort' => 'oldest', 'per_page' => 25])
        ->where('queue.data.0.can_update_status', false)->has('websites', 1));
});

test('work queue submission links require a unique forward and reverse website association', function (string $case) {
    $metadata = [['key' => '_fluent_id', 'value' => '123']];
    if ($case === 'conflicting') {
        $metadata[] = ['key' => '_fluent_id', 'value' => '456'];
    } elseif ($case === 'malformed') {
        $metadata = [['key' => '_fluent_id', 'value' => ['123']]];
    } elseif ($case === 'integer') {
        $metadata[0]['value'] = 123;
    }
    $order = workQueueOrder($this->site, 1, ['payload' => ['meta_data' => $metadata, 'private' => 'private-payload']]);
    $entry = FfSubmission::create(['website_id' => $this->site->id, 'form_id' => 1, 'entry_id' => 123, 'payload' => ['private' => 'private-entry']]);
    if ($case === 'duplicate forms') {
        FfSubmission::create(['website_id' => $this->site->id, 'form_id' => 2, 'entry_id' => 123, 'payload' => []]);
    } elseif ($case === 'duplicate orders') {
        workQueueOrder($this->site, 2, ['status' => 'completed', 'payload' => ['meta_data' => $metadata]]);
    } elseif ($case === 'different website') {
        $second = Website::create(['user_id' => $this->actor->id, 'name' => 'Second', 'slug' => 'second', 'base_url' => 'https://second.example.test']);
        $entry->update(['website_id' => $second->id]);
    }
    $this->actingAs($this->actor)->getJson('/order-work-queue')->assertOk()
        ->assertJsonPath('queue.data.0.submission_id', in_array($case, ['unique', 'integer'], true) ? $entry->id : null)
        ->assertDontSee('private-entry')->assertDontSee('private-payload');
})->with(['unique', 'integer', 'conflicting', 'malformed', 'duplicate forms', 'duplicate orders', 'different website']);

test('work queue batches visible-page enrichment and never selects encrypted delivery contents', function () {
    foreach (range(1, 30) as $index) {
        $order = workQueueOrder($this->site, $index, ['payload' => ['meta_data' => [['key' => '_fluent_id', 'value' => $index]]]]);
        FfSubmission::create(['website_id' => $this->site->id, 'form_id' => 1, 'entry_id' => $index, 'payload' => []]);
        $email = workQueueEmail($order, $this->actor, $this->gmail, 'prepared');
        // If the read path hydrates encrypted contents it will fail decryption.
        DB::table('order_email_deliveries')->where('id', $email->id)->update(['mime' => 'invalid-encrypted-mime', 'snapshot' => 'invalid-encrypted-snapshot']);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $response = $this->actingAs($this->actor)->getJson('/order-work-queue')->assertOk()->assertJsonCount(25, 'queue.data');
        $queries = collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/\b(wc_orders|order_email_deliveries|ff_submissions|websites)\b/', $query['query']));
    } finally {
        DB::disableQueryLog();
    }
    expect($queries)->toHaveCount(6);
    foreach ($queries as $query) {
        expect(strtolower($query['query']))->not->toContain('snapshot', 'select * from "order_email_deliveries"', 'update ', 'delete ');
    }
    expect($queries->filter(fn ($query) => str_contains($query['query'], ' as "metadata"'))->sole()['bindings'])->toHaveCount(25);
    expect(array_column($response->json('queue.data'), 'submission_id'))->not->toContain(null);
    Http::assertNothingSent();
});
