<?php

use App\Models\FfForm;
use App\Models\FfSubmission;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\WebhookEvent;
use App\Models\Website;
use App\Services\DashboardOverview;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Carbon::setTestNow('2026-09-07 12:30:45');
    CarbonImmutable::setTestNow('2026-09-07 12:30:45');
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->website = dashboardWebsite($this->owner, 'Alpha');
});

afterEach(function () {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
});

function dashboardWebsite(User $user, string $name): Website
{
    return Website::create(['user_id' => $user->id, 'name' => $name, 'slug' => strtolower($name), 'base_url' => 'https://'.strtolower($name).'.example', 'status' => 'active']);
}

function dashboardOrder(Website $website, int $id, string $date = '2026-09-07 10:00:00', array $values = []): WcOrder
{
    return WcOrder::create(array_replace([
        'website_id' => $website->id, 'wp_order_id' => $id, 'status' => 'completed', 'currency' => 'USD', 'total' => 25,
        'customer_email' => 'demo@example.test', 'customer_name' => 'Demo customer', 'created_at_wp' => $date,
        'payload' => ['private' => 'must-not-load'],
    ], $values));
}

function dashboardSubmission(Website $website, int $id, string $date = '2026-09-07 10:00:00', array $values = []): FfSubmission
{
    return FfSubmission::create(array_replace([
        'website_id' => $website->id, 'form_id' => 4, 'entry_id' => $id, 'email' => 'form@example.test',
        'created_at_wp' => $date, 'payload' => ['private' => 'must-not-load'],
    ], $values));
}

function dashboardWebhook(Website $website, string $status, string $received, string $source = 'woocommerce'): WebhookEvent
{
    return WebhookEvent::create(['website_id' => $website->id, 'source' => $source, 'topic' => 'demo', 'status' => $status,
        'received_at' => $received, 'processed_at' => '2026-09-07 12:00:00', 'payload' => ['private' => 'must-not-load']]);
}

test('dashboard separates currencies and uses only completed orders for revenue and AOV', function () {
    dashboardOrder($this->website, 1, values: ['currency' => 'usd', 'total' => 10.10, 'customer_email' => ' Mixed@Example.test ']);
    dashboardOrder($this->website, 2, values: ['currency' => 'USD', 'total' => 20.20, 'customer_email' => 'mixed@example.test']);
    dashboardOrder($this->website, 3, values: ['currency' => 'EUR', 'total' => 50, 'customer_email' => 'other@example.test']);
    dashboardOrder($this->website, 4, values: ['currency' => 'USD', 'total' => 900, 'status' => 'pending', 'customer_email' => '']);
    dashboardOrder($this->website, 5, '2026-08-30 10:00:00', ['currency' => 'USD', 'total' => 15.15]);
    dashboardOrder($this->website, 6, '2026-08-30 10:00:00', ['currency' => 'GBP', 'total' => 40]);
    $data = app(DashboardOverview::class)->build($this->owner, []);
    expect($data['summary']['orders'])->toMatchArray(['current' => 4, 'previous' => 2, 'change' => 2, 'change_percent' => 100]);
    expect($data['summary']['customers']['current'])->toBe(2);
    $revenue = collect($data['revenue'])->keyBy('currency');
    expect($revenue->keys()->all())->toBe(['EUR', 'GBP', 'USD']);
    expect($revenue['USD'])->toMatchArray(['current' => 30.30, 'previous' => 15.15, 'completed_orders' => 2, 'average_order_value' => 15.15, 'change_percent' => 100]);
    expect($revenue['EUR'])->toMatchArray(['current' => 50, 'previous' => 0, 'change_percent' => null]);
    expect($revenue['GBP'])->toMatchArray(['current' => 0, 'previous' => 40, 'change_percent' => -100]);
    expect($data)->not->toHaveKey('total_revenue');
    expect($data['websitePerformance'][0]['revenue'])->toHaveCount(2);
    Http::assertNothingSent();
});

test('dashboard month window includes exact boundaries and comparable elapsed previous days', function () {
    foreach (['2026-09-01 00:00:00', '2026-09-07 12:30:45', '2026-08-25 00:00:00', '2026-08-31 12:30:45', '2026-08-24 23:59:59', '2026-08-31 12:30:46', '2026-09-07 12:30:46', '2026-09-08 00:00:00'] as $id => $date) {
        dashboardOrder($this->website, $id + 1, $date);
        dashboardSubmission($this->website, $id + 1, $date);
    }
    $data = app(DashboardOverview::class)->build($this->owner, []);
    expect($data['period'])->toMatchArray(['label' => 'Month to date', 'start_date' => '2026-09-01', 'end_date' => '2026-09-07', 'previous_start_date' => '2026-08-25', 'previous_end_date' => '2026-08-31', 'timezone' => 'UTC']);
    expect($data['summary']['orders'])->toMatchArray(['current' => 2, 'previous' => 2]);
    expect($data['summary']['submissions'])->toMatchArray(['current' => 2, 'previous' => 2]);
    expect($data['recentOrders'])->toHaveCount(2);
    expect($data['generated_at'])->toBe('2026-09-07T12:30:45+00:00');
});

test('dashboard periods produce exactly the requested calendar days with zeros', function (string $period, string $start, int $days, string $previousStart) {
    $data = app(DashboardOverview::class)->build($this->owner, ['period' => $period]);
    expect($data['period']['start_date'])->toBe($start);
    expect($data['period']['previous_start_date'])->toBe($previousStart);
    expect($data['trend'])->toHaveCount($days);
    expect(array_sum(array_column($data['trend'], 'orders')))->toBe(0);
    expect(array_sum(array_column($data['trend'], 'submissions')))->toBe(0);
    expect($data['summary']['orders'])->toMatchArray(['current' => 0, 'previous' => 0, 'change_percent' => 0]);
    expect($data['trend'][0]['revenue'])->toBeInstanceOf(stdClass::class);
})->with([
    ['today', '2026-09-07', 1, '2026-09-06'],
    ['7d', '2026-09-01', 7, '2026-08-25'],
    ['30d', '2026-08-09', 30, '2026-07-10'],
    ['month', '2026-09-01', 7, '2026-08-25'],
]);

test('daily dashboard trends include empty days and zero each completed currency', function () {
    dashboardOrder($this->website, 1, '2026-09-02 10:00:00', ['currency' => 'EUR', 'total' => 35]);
    dashboardOrder($this->website, 2, '2026-09-07 10:00:00', ['currency' => 'USD', 'total' => 25]);
    dashboardSubmission($this->website, 1, '2026-09-03 10:00:00');
    $days = collect(app(DashboardOverview::class)->build($this->owner, ['period' => '7d'])['trend'])->keyBy('date');
    expect((array) $days['2026-09-01']['revenue'])->toBe(['EUR' => 0, 'USD' => 0]);
    expect((array) $days['2026-09-02']['revenue'])->toBe(['EUR' => 35, 'USD' => 0]);
    expect($days['2026-09-02']['orders'])->toBe(1);
    expect($days['2026-09-03']['submissions'])->toBe(1);
    expect($days['2026-09-04']['orders'])->toBe(0);
});

test('tenant and website filters scope every dashboard section while admins can select any website', function () {
    $second = dashboardWebsite($this->owner, 'Beta');
    $foreign = dashboardWebsite(User::factory()->create(), 'Foreign');
    foreach ([$this->website, $second, $foreign] as $site) {
        dashboardOrder($site, 1, values: ['status' => 'processing']);
        dashboardOrder($site, 2);
        dashboardSubmission($site, 1);
        dashboardWebhook($site, 'failed', '2026-09-07 10:00:00');
    }
    $this->actingAs($this->owner)->getJson(route('dashboard'))->assertOk()
        ->assertJsonCount(2, 'websites')->assertJsonPath('summary.orders.current', 4)->assertJsonPath('operations.failed_webhooks', 2);
    $this->getJson(route('dashboard', ['website_id' => $second->id]))->assertOk()
        ->assertJsonPath('filters.website_id', $second->id)->assertJsonPath('summary.orders.current', 2)
        ->assertJsonCount(1, 'websitePerformance')->assertJsonCount(1, 'websiteHealth')->assertJsonCount(1, 'recentSubmissions')
        ->assertJsonPath('recentOrders.0.website_id', $second->id)->assertJsonPath('operations.open_orders', 1);
    $this->getJson(route('dashboard', ['website_id' => $foreign->id]))->assertForbidden();
    $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson(route('dashboard', ['website_id' => $foreign->id]))
        ->assertOk()->assertJsonCount(3, 'websites')->assertJsonPath('summary.orders.current', 2)->assertJsonPath('recentSubmissions.0.website_id', $foreign->id);
});

test('dashboard rejects invalid filter values', function (array $filters) {
    $this->actingAs($this->owner)->getJson(route('dashboard', $filters))->assertUnprocessable();
    Http::assertNothingSent();
})->with([[['period' => 'year']], [['period' => ['today']]], [['website_id' => -1]], [['website_id' => 'all']], [['website_id' => [1]]]]);

test('dashboard recent activity joins form titles by website and keeps deterministic ties', function () {
    $second = dashboardWebsite($this->owner, 'Beta');
    FfForm::create(['website_id' => $this->website->id, 'form_id' => 4, 'title' => 'Visa request', 'fields' => ['secret-schema']]);
    FfForm::create(['website_id' => $second->id, 'form_id' => 4, 'title' => 'Other title', 'fields' => []]);
    $first = dashboardOrder($this->website, 1);
    $last = dashboardOrder($this->website, 2);
    dashboardSubmission($this->website, 1);
    dashboardSubmission($this->website, 2, values: ['form_id' => 99]);
    $response = $this->actingAs($this->owner)->getJson(route('dashboard', ['website_id' => $this->website->id]))->assertOk()
        ->assertJsonPath('recentOrders.0.id', $last->id)->assertJsonPath('recentOrders.1.id', $first->id)
        ->assertJsonPath('recentSubmissions.0.form_title', 'Form #99')->assertJsonPath('recentSubmissions.1.form_title', 'Visa request')
        ->assertJsonPath('recentSubmissions.0.created_at_wp', '2026-09-07T10:00:00+00:00');
    expect($response->getContent())->not->toContain('must-not-load')->not->toContain('secret-schema')->not->toContain('Other title');
});

test('dashboard operational backlog is all time and webhook freshness uses received time', function () {
    dashboardOrder($this->website, 1, '2025-01-01 00:00:00', ['status' => 'pending']);
    dashboardOrder($this->website, 2, '2025-01-01 00:00:00', ['status' => 'on-hold']);
    dashboardOrder($this->website, 3, '2025-01-01 00:00:00', ['status' => 'processing']);
    dashboardWebhook($this->website, 'queued', '2025-01-01 00:00:00');
    dashboardWebhook($this->website, 'failed', '2026-09-05 10:00:00');
    dashboardWebhook($this->website, 'failed', '2026-09-06 12:30:45', 'fluentforms');
    dashboardWebhook($this->website, 'processed', '2026-09-07 11:00:00', 'fluentforms');
    $data = app(DashboardOverview::class)->build($this->owner, ['period' => 'today']);
    expect($data['summary']['orders']['current'])->toBe(0);
    expect($data['operations'])->toMatchArray([
        'open_orders' => 3, 'pending' => 1, 'on_hold' => 1, 'processing' => 1,
        'queued_webhooks' => 1, 'failed_webhooks' => 2, 'processed_webhooks' => 1, 'failed_webhooks_24h' => 1,
        'oldest_queued_at' => '2025-01-01T00:00:00+00:00', 'last_received_at' => '2026-09-07T11:00:00+00:00',
        'last_woo_received_at' => '2026-09-05T10:00:00+00:00', 'last_fluent_received_at' => '2026-09-07T11:00:00+00:00',
    ]);
    expect($data['websiteHealth'][0])->toMatchArray(['orders_count' => 0, 'open_orders' => 3, 'failed_webhooks' => 2]);
});

test('dashboard builds a bounded snapshot with eight queries and no payload selection', function () {
    foreach (range(1, 30) as $id) {
        dashboardOrder($this->website, $id);
        dashboardSubmission($this->website, $id);
    }
    DB::flushQueryLog();
    DB::enableQueryLog();
    try {
        $data = app(DashboardOverview::class)->build($this->owner, []);
        $queries = DB::getQueryLog();
    } finally {
        DB::disableQueryLog();
    }
    expect($queries)->toHaveCount(8);
    foreach ($queries as $query) {
        expect(strtolower($query['query']))->not->toContain('payload')->not->toContain('select *');
    }
    expect($data['summary']['orders']['current'])->toBe(30);
    expect($data['recentOrders'])->toHaveCount(10);
    expect($data['recentSubmissions'])->toHaveCount(10);
});

test('dashboard JSON exposes the complete private snapshot contract', function () {
    $this->actingAs($this->owner)->getJson(route('dashboard'))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonStructure(['filters', 'period', 'websites', 'summary', 'revenue', 'trend', 'statusBreakdown', 'websitePerformance', 'recentOrders', 'recentSubmissions', 'operations', 'websiteHealth', 'generated_at']);
});

test('dashboard Inertia render includes the scoped snapshot', function () {
    $this->actingAs($this->owner)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->component('Dashboard')
        ->where('filters.period', 'month')->where('summary.orders.current', 0)->has('trend', 7));
});
