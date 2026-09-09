<?php

use App\Jobs\TrafficRefreshReport;
use App\Models\TrafficAppSetting;
use App\Models\TrafficConnection;
use App\Models\TrafficReport;
use App\Models\TrafficWebsite;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Services\GoogleReportingClient;
use App\Services\TrafficGoogleReports;
use App\Services\TrafficReporting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    $this->travelTo(now()->setDate(2026, 9, 9)->startOfDay());
    Http::preventStrayRequests();
    Queue::fake();
    $this->user = User::factory()->create();
    $this->website = Website::create(['user_id' => $this->user->id, 'name' => 'Traffic demo', 'slug' => 'traffic-demo', 'base_url' => 'https://traffic.example.test']);
    TrafficAppSetting::create(['id' => 1, 'client_id' => 'reporting.apps.googleusercontent.com', 'client_secret' => 'synthetic-secret']);
    $this->connection = TrafficConnection::create([
        'user_id' => $this->user->id, 'connection_key' => (string) Str::uuid(), 'app_fingerprint' => app(GoogleReportingClient::class)->fingerprint(),
        'email' => 'reporting@example.test', 'access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh',
        'expires_at' => now()->addHour(), 'connected_at' => now(),
    ]);
    $this->mapping = TrafficWebsite::create([
        'user_id' => $this->user->id, 'website_id' => $this->website->id, 'connection_key' => $this->connection->connection_key,
        'mapping_key' => (string) Str::uuid(), 'ga4_property_id' => '123456', 'gsc_site_url' => 'sc-domain:traffic.example.test',
    ]);
    $this->range = ['website_id' => null, 'start_date' => '2026-09-01', 'end_date' => '2026-09-08'];
    $this->service = app(TrafficReporting::class);
});

function trafficFakeSources($test, ?Closure $beforeReturn = null): void
{
    app()->instance(TrafficGoogleReports::class, Mockery::mock(TrafficGoogleReports::class, function ($mock) use ($beforeReturn) {
        $mock->shouldReceive('ga4')->once()->andReturnUsing(function () use ($beforeReturn) {
            $beforeReturn?->__invoke();

            return ['data' => ['totals' => ['users' => 12]], 'notes' => ['Synthetic note']];
        });
        $mock->shouldReceive('gsc')->once()->andReturn(['data' => ['totals' => ['clicks' => 8]], 'notes' => []]);
    }));
}

function trafficRunQueued($test): TrafficReport
{
    $report = TrafficReport::firstOrFail();
    (new TrafficRefreshReport($report->id, $report->run_key))->handle(app(TrafficReporting::class));

    return $report->fresh();
}

test('traffic page and status read cached local data only with complete default dates', function () {
    $this->actingAs($this->user)->get(route('traffic.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Traffic', false)->where('traffic.filters', ['website_id' => null, 'start_date' => '2026-08-12', 'end_date' => '2026-09-08'])
        ->where('traffic.connection.connected', true)->where('traffic.reports.0.status', 'missing')
        ->where('traffic.orders.total', 0)->missing('traffic.connection.access_token'));
    $this->getJson(route('traffic.status', $this->range))->assertOk()->assertJsonPath('reports.0.status', 'missing')->assertHeader('Cache-Control', 'no-store, private');
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    expect(TrafficReport::count())->toBe(0);
});

test('traffic requests require login and cannot select another owners website', function () {
    $this->get(route('traffic.index'))->assertRedirect(route('login'));
    $other = Website::create(['user_id' => User::factory()->create()->id, 'name' => 'Other', 'slug' => 'traffic-other', 'base_url' => 'https://other.example.test']);
    foreach (['traffic.index', 'traffic.status', 'traffic.realtime'] as $route) {
        $this->actingAs($this->user)->getJson(route($route, ['website_id' => $other->id]))->assertForbidden();
    }
    $this->postJson(route('traffic.refresh'), [...$this->range, 'website_id' => $other->id])->assertForbidden();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('traffic dates reject invalid reversed oversized and future ranges', function (array $range) {
    $this->actingAs($this->user)->postJson(route('traffic.refresh'), $range)->assertUnprocessable();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([
    [['start_date' => '2026-02-30']], [['end_date' => '2026-09-10']],
    [['start_date' => '2026-09-08', 'end_date' => '2026-09-07']],
    [['start_date' => '2026-01-01', 'end_date' => '2026-04-04']],
    [['start_date' => ['2026-09-01']]], [['website_id' => ['1']]],
]);

test('traffic accepts exactly 93 complete days and preserves one-sided default logic', function () {
    $this->actingAs($this->user)->getJson(route('traffic.status', ['start_date' => '2026-01-01', 'end_date' => '2026-04-03']))->assertOk();
    $this->get(route('traffic.index', ['end_date' => '2026-08-31']))->assertInertia(fn (Assert $page) => $page->where('traffic.filters.start_date', '2026-08-04'));
});

test('traffic order totals are scoped by website and UTC full-day boundaries with separate currencies', function () {
    foreach ([['2026-08-31 23:59:59', 'completed', 'USD', 999], ['2026-09-01 00:00:00', 'completed', 'usd', 10], ['2026-09-08 23:59:59', 'completed', 'EUR', 15], ['2026-09-09 00:00:00', 'completed', 'USD', 999], ['2026-09-03 12:00:00', 'pending', 'USD', 30]] as $id => [$date, $status, $currency, $total]) {
        WcOrder::create(['website_id' => $this->website->id, 'wp_order_id' => $id + 1, 'created_at_wp' => $date, 'status' => $status, 'currency' => $currency, 'total' => $total, 'payload' => ['private' => 'do-not-show']]);
    }
    $this->actingAs($this->user)->get(route('traffic.index', $this->range))->assertInertia(fn (Assert $page) => $page
        ->where('traffic.orders.total', 3)->where('traffic.orders.completed', 2)
        ->where('traffic.orders.revenue', [['currency' => 'EUR', 'total' => 15], ['currency' => 'USD', 'total' => 10]]));
});

test('traffic refresh dispatches a dedicated bounded job and deduplicates overlapping refreshes', function () {
    $this->actingAs($this->user)->postJson(route('traffic.refresh'), $this->range)->assertStatus(202);
    $this->postJson(route('traffic.refresh'), $this->range)->assertStatus(202);
    Queue::assertPushed(TrafficRefreshReport::class, 1);
    Queue::assertPushed(TrafficRefreshReport::class, fn ($job) => $job->connection === 'traffic' && $job->queue === 'traffic' && $job->timeout === 180 && $job->tries === 1);
    expect(TrafficReport::count())->toBe(1)->and(TrafficReport::first()->status)->toBe('queued');
    Http::assertNothingSent();
});

test('traffic job caches successful sources once and skips fresh repeated refresh', function () {
    $this->service->queue($this->user, $this->range);
    trafficFakeSources($this);
    $report = TrafficReport::firstOrFail();
    $job = new TrafficRefreshReport($report->id, $report->run_key);
    $job->handle(app(TrafficReporting::class));
    $job->handle(app(TrafficReporting::class));
    expect($report->fresh()->status)->toBe('ready');
    $this->actingAs($this->user)->getJson(route('traffic.status', $this->range))->assertOk()
        ->assertJsonPath('reports.0.ga4.totals.users', 12)->assertJsonPath('reports.0.gsc.totals.clicks', 8)
        ->assertJsonMissingPath('reports.0.cache_key')->assertJsonMissingPath('reports.0.connection_key')->assertJsonMissingPath('reports.0.mapping_key');
    expect($this->service->queue($this->user, $this->range))->toBe(0);
    $this->travel(61)->minutes();
    expect($this->service->queue($this->user, $this->range))->toBe(1);
    $current = $this->service->reports($this->user, $this->range)[0];
    expect($current['status'])->toBe('queued')->and($current['ga4']['totals']['users'])->toBe(12);
});

test('traffic source failure preserves the successful source and never persists raw errors as zero success', function () {
    $this->service->queue($this->user, $this->range);
    $this->mock(TrafficGoogleReports::class, function ($mock) {
        $mock->shouldReceive('ga4')->once()->andThrow(new RuntimeException('private-token customer@example.test google raw body'));
        $mock->shouldReceive('gsc')->once()->andReturn(['data' => ['totals' => ['clicks' => 8]], 'notes' => []]);
    });
    $report = trafficRunQueued($this);
    expect($report->status)->toBe('failed')->and($report->payload['ga4'])->toBeNull()->and($report->payload['gsc']['totals']['clicks'])->toBe(8)
        ->and($report->error)->toContain('Google Analytics')->not->toContain('private-token', 'customer@example.test', 'raw body');
    expect($this->service->queue($this->user, $this->range))->toBe(0);
    $this->travel(6)->minutes();
    expect($this->service->queue($this->user, $this->range))->toBe(1);
});

test('traffic disconnect mapping generation credential and ownership changes invalidate cached reports', function (string $change) {
    $this->service->queue($this->user, $this->range);
    trafficFakeSources($this);
    trafficRunQueued($this);
    match ($change) {
        'disconnect' => $this->connection->delete(),
        'connection' => $this->connection->update(['connection_key' => (string) Str::uuid()]),
        'mapping' => $this->mapping->update(['mapping_key' => (string) Str::uuid()]),
        'credentials' => TrafficAppSetting::find(1)->update(['client_secret' => 'changed']),
        'ownership' => $this->website->update(['user_id' => User::factory()->create()->id]),
    };
    $reports = app(TrafficReporting::class)->reports($this->user, $this->range);
    if ($change === 'ownership') {
        expect($reports)->toBe([]);
    } else {
        expect($reports[0]['ga4'])->toBeNull()->and($reports[0]['gsc'])->toBeNull();
    }
})->with(['disconnect', 'connection', 'mapping', 'credentials', 'ownership']);

test('traffic obsolete jobs do not call Google or revive stale mappings', function () {
    $this->service->queue($this->user, $this->range);
    $this->mapping->update(['mapping_key' => (string) Str::uuid()]);
    $this->mock(TrafficGoogleReports::class, fn ($mock) => $mock->shouldNotReceive('ga4', 'gsc'));
    expect(trafficRunQueued($this)->status)->toBe('failed');
    Http::assertNothingSent();
});

test('traffic mapping replacement during external reads discards the response', function () {
    $this->service->queue($this->user, $this->range);
    trafficFakeSources($this, fn () => $this->mapping->update(['mapping_key' => (string) Str::uuid()]));
    $report = trafficRunQueued($this);
    expect($report->payload)->toBeNull()->and($report->status)->toBe('failed');
});

test('traffic reports and mappings remain personal even for administrators', function () {
    $this->service->queue($this->user, $this->range);
    trafficFakeSources($this);
    trafficRunQueued($this);
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->getJson(route('traffic.status', $this->range))->assertOk()
        ->assertJsonPath('reports.0.status', 'unlinked')->assertJsonPath('reports.0.ga4', null);
    $this->postJson(route('traffic.refresh'), $this->range)->assertStatus(409);
});

test('traffic expired authorization remains a visible failed source without cached metrics on status or page', function () {
    $this->service->queue($this->user, $this->range);
    trafficFakeSources($this);
    trafficRunQueued($this);
    $this->connection->update(['reconnect_required' => true]);
    $this->actingAs($this->user)->getJson(route('traffic.status', $this->range))->assertOk()
        ->assertJsonPath('reports.0.status', 'failed')->assertJsonPath('reports.0.ga4_property_id', '123456')
        ->assertJsonPath('reports.0.ga4', null)->assertJsonPath('reports.0.gsc', null)->assertJsonPath('reports.0.updated_at', null)
        ->assertJsonPath('reports.0.error', 'Google reporting authorization expired or was revoked. Reconnect your reporting account in Traffic & SEO settings.');
    $this->get(route('traffic.index', $this->range))->assertInertia(fn (Assert $page) => $page
        ->where('traffic.connection.connected', false)->where('traffic.reports.0.status', 'failed')
        ->where('traffic.reports.0.ga4', null)->where('traffic.reports.0.gsc', null));
    $this->postJson(route('traffic.refresh'), $this->range)->assertStatus(409);
    Http::assertNothingSent();
    Queue::assertPushed(TrafficRefreshReport::class, 1);
});

test('traffic stuck work can be reclaimed and a superseded job cannot clobber the new generation', function () {
    $this->service->queue($this->user, $this->range);
    $old = TrafficReport::first();
    $oldKey = $old->run_key;
    $this->travel(16)->minutes();
    expect($this->service->reports($this->user, $this->range)[0]['status'])->toBe('failed');
    expect($this->service->queue($this->user, $this->range))->toBe(1);
    (new TrafficRefreshReport($old->id, $oldKey))->handle($this->service);
    (new TrafficRefreshReport($old->id, $oldKey))->failed(new RuntimeException('private'));
    expect($old->fresh()->status)->toBe('queued')->and($old->fresh()->run_key)->not->toBe($oldKey);
    Http::assertNothingSent();
});

test('traffic realtime requires explicit selected GA4 mapping and caches one property for sixty seconds', function () {
    $this->mock(TrafficGoogleReports::class, fn ($mock) => $mock->shouldReceive('realtime')->twice()->withArgs(fn ($connection, $property) => $connection->id === $this->connection->id && $property === '123456')->andReturn(9, 11));
    $this->actingAs($this->user)->getJson(route('traffic.realtime'))->assertUnprocessable();
    $url = route('traffic.realtime', ['website_id' => $this->website->id]);
    $this->getJson($url)->assertOk()->assertJsonPath('active_users', 9);
    $this->getJson($url)->assertOk()->assertJsonPath('active_users', 9);
    $this->travel(61)->seconds();
    $this->getJson($url)->assertOk()->assertJsonPath('active_users', 11);
    $this->mapping->update(['ga4_property_id' => null]);
    $this->getJson($url)->assertUnprocessable();
});

test('traffic realtime failure is a safe error rather than a cached zero', function () {
    $this->mock(TrafficGoogleReports::class, fn ($mock) => $mock->shouldReceive('realtime')->once()->andThrow(new RuntimeException('secret-provider-body')));
    $this->actingAs($this->user)->getJson(route('traffic.realtime', ['website_id' => $this->website->id]))->assertStatus(502)
        ->assertJsonMissingPath('active_users')->assertDontSee('secret-provider-body');
});

test('traffic timeout does not relabel an older successful payload as freshly updated', function () {
    $this->service->queue($this->user, $this->range);
    $report = TrafficReport::firstOrFail();
    $report->update(['status' => 'running', 'payload' => ['ga4' => ['totals' => ['users' => 999]]]]);
    (new TrafficRefreshReport($report->id, $report->run_key))->failed(new RuntimeException('provider-private-body'));
    expect($report->fresh()->status)->toBe('failed')->and($report->fresh()->payload)->toBeNull()
        ->and($report->fresh()->error)->not->toContain('provider-private-body');
});

test('traffic stale report pruning preserves recent snapshots', function () {
    $this->service->queue($this->user, $this->range);
    $old = TrafficReport::firstOrFail();
    $old->updated_at = now()->subDays(31);
    $old->save(['timestamps' => false]);
    $this->service->queue($this->user, [...$this->range, 'start_date' => '2026-09-02']);
    $this->artisan('traffic:prune-reports')->assertSuccessful();
    expect(TrafficReport::count())->toBe(1)->and(TrafficReport::find($old->id))->toBeNull();
});

test('traffic status checks do not exhaust the refresh endpoint rate limit', function () {
    $this->actingAs($this->user);
    foreach (range(1, 7) as $ignored) {
        $this->getJson(route('traffic.status', $this->range))->assertOk();
    }
    $this->postJson(route('traffic.refresh'), $this->range)->assertStatus(202);
    Queue::assertPushed(TrafficRefreshReport::class, 1);
});

test('traffic missing and unlinked services do not silently inherit another mapping cache', function () {
    $this->mapping->update(['ga4_property_id' => null, 'gsc_site_url' => null]);
    expect($this->service->queue($this->user, $this->range))->toBe(0)->and($this->service->reports($this->user, $this->range)[0]['status'])->toBe('unlinked');
    Queue::assertNothingPushed();
    $this->mapping->update(['gsc_site_url' => 'sc-domain:traffic.example.test']);
    expect($this->service->queue($this->user, $this->range))->toBe(1);
    $this->mock(TrafficGoogleReports::class, function ($mock) {
        $mock->shouldNotReceive('ga4');
        $mock->shouldReceive('gsc')->once()->andReturn(['data' => ['totals' => ['clicks' => 5]], 'notes' => []]);
    });
    $report = trafficRunQueued($this);
    expect($report->status)->toBe('ready')->and($report->payload['ga4'])->toBeNull()->and($report->payload['gsc']['totals']['clicks'])->toBe(5);
});

test('traffic realtime discards an in-flight result after a mapping change', function () {
    $this->mock(TrafficGoogleReports::class, function ($mock) {
        $mock->shouldReceive('realtime')->once()->andReturnUsing(function () {
            $this->mapping->update(['mapping_key' => (string) Str::uuid()]);

            return 123;
        });
    });
    $this->actingAs($this->user)->getJson(route('traffic.realtime', ['website_id' => $this->website->id]))->assertStatus(409)->assertJsonMissingPath('active_users');
});

test('traffic queue worker is isolated and lease exceeds the job timeout', function () {
    expect(config('queue.connections.traffic.driver'))->toBe('database')->and(config('queue.connections.traffic.queue'))->toBe('traffic')
        ->and(config('queue.connections.traffic.retry_after'))->toBeGreaterThan((new TrafficRefreshReport(1, 'test'))->timeout);
    $events = app(\Illuminate\Console\Scheduling\Schedule::class)->events();
    $drain = collect($events)->first(fn ($event) => str_contains($event->command ?? '', 'queue:work traffic'));
    expect($drain)->not->toBeNull()->and($drain->command)->toContain('--queue=traffic', '--stop-when-empty', '--max-jobs=4', '--max-time=50')
        ->and($drain->runInBackground)->toBeTrue()->and($drain->withoutOverlapping)->toBeTrue();
});
