<?php

use App\Jobs\SeoRefreshReport;
use App\Models\SeoReport;
use App\Models\TrafficAppSetting;
use App\Models\TrafficConnection;
use App\Models\TrafficWebsite;
use App\Models\User;
use App\Models\Website;
use App\Services\GoogleReportingClient;
use App\Services\SeoGoogleReports;
use App\Services\SeoReporting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-09 12:00:00', 'UTC'));
    Http::preventStrayRequests();
    Queue::fake();
    $this->user = User::factory()->create();
    $this->website = Website::create(['user_id' => $this->user->id, 'name' => 'SEO demo', 'slug' => 'seo-demo', 'base_url' => 'https://seo.example.test']);
    TrafficAppSetting::create(['id' => 1, 'client_id' => 'reporting.apps.googleusercontent.com', 'client_secret' => 'synthetic-secret']);
    $this->connection = TrafficConnection::create([
        'user_id' => $this->user->id, 'connection_key' => (string) Str::uuid(), 'app_fingerprint' => app(GoogleReportingClient::class)->fingerprint(),
        'email' => 'reporting@example.test', 'access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh',
        'expires_at' => now()->addHour(), 'connected_at' => now(),
    ]);
    $this->mapping = TrafficWebsite::create([
        'user_id' => $this->user->id, 'website_id' => $this->website->id, 'connection_key' => $this->connection->connection_key,
        'mapping_key' => (string) Str::uuid(), 'ga4_property_id' => '123456', 'gsc_site_url' => 'sc-domain:seo.example.test',
    ]);
    $this->range = ['website_id' => null, 'start_date' => '2026-09-01', 'end_date' => '2026-09-06'];
    $this->pageUrl = 'https://seo.example.test/booking';
});

function seoLifecyclePayload(array $pages = []): array
{
    return [
        'opportunities' => [], 'opportunity_count' => 0,
        'coverage' => ['queries' => 1, 'pages' => count($pages), 'previous_queries' => 0, 'previous_pages' => 0, 'row_limit' => 1000],
        'previous_start_date' => '2026-08-26', 'previous_end_date' => '2026-08-31',
        'notes' => ['Synthetic coverage note'], 'queries' => [], 'page_names' => $pages,
    ];
}

function seoLifecycleRun($test, ?SeoReport $report = null): SeoReport
{
    $report ??= SeoReport::whereNull('page_url')->firstOrFail();
    (new SeoRefreshReport($report->id, $report->run_key))->handle(app(SeoReporting::class));

    return $report->fresh();
}

function seoLifecycleReady($test): SeoReport
{
    app(SeoReporting::class)->queue($test->user, $test->range);
    app()->instance(SeoGoogleReports::class, Mockery::mock(SeoGoogleReports::class, function ($mock) use ($test) {
        $mock->shouldReceive('report')->once()->andReturn(seoLifecyclePayload([$test->pageUrl]));
    }));

    return seoLifecycleRun($test);
}

test('SEO page and status are local only and expose Pacific processing-buffer defaults', function () {
    $this->actingAs($this->user)->get(route('seo.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Seo', false)->where('seo.filters', ['website_id' => null, 'start_date' => '2026-08-10', 'end_date' => '2026-09-06'])
        ->where('seo.max_end_date', '2026-09-06')->where('seo.connection.connected', true)
        ->where('seo.reports.0.status', 'missing')->where('seo.reports.0.data', null)->missing('seo.connection.access_token'));
    $this->getJson(route('seo.status', $this->range))->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    Http::assertNothingSent();
    Queue::assertNothingPushed();
    expect(SeoReport::count())->toBe(0);
});

test('SEO maximum date uses Pacific calendar across UTC day boundary', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-09-09 01:00:00', 'UTC'));
    $this->actingAs($this->user)->get(route('seo.index'))->assertInertia(fn (Assert $page) => $page
        ->where('seo.max_end_date', '2026-09-05')->where('seo.filters.end_date', '2026-09-05'));
    $this->getJson(route('seo.status', ['end_date' => '2026-09-06']))->assertUnprocessable()->assertJsonValidationErrors('end_date')
        ->assertSee('exclude the latest three days');
});

test('SEO dates reject malformed reversed oversized or insufficiently processed ranges', function (array $range) {
    $this->actingAs($this->user)->postJson(route('seo.refresh'), $range)->assertUnprocessable();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([
    [['start_date' => '2026-02-30']], [['end_date' => '2026-09-07']],
    [['start_date' => '2026-09-06', 'end_date' => '2026-09-05']],
    [['start_date' => '2026-01-01', 'end_date' => '2026-04-04']],
    [['start_date' => ['2026-09-01']]], [['website_id' => ['1']]],
    [['page_url' => 'https://seo.example.test/booking']],
]);

test('SEO accepts exactly 93 inclusive days and one-sided end-date defaults', function () {
    $this->actingAs($this->user)->getJson(route('seo.status', ['start_date' => '2026-01-01', 'end_date' => '2026-04-03']))->assertOk();
    $this->get(route('seo.index', ['end_date' => '2026-08-31']))->assertInertia(fn (Assert $page) => $page->where('seo.filters.start_date', '2026-08-04'));
});

test('SEO endpoints require authentication and enforce website ownership', function () {
    foreach (['seo.index', 'seo.status', 'seo.page'] as $name) {
        $this->get(route($name))->assertRedirect(route('login'));
    }
    foreach (['seo.refresh', 'seo.page.refresh'] as $name) {
        $this->post(route($name))->assertRedirect(route('login'));
    }
    $other = Website::create(['user_id' => User::factory()->create()->id, 'name' => 'Other', 'slug' => 'seo-other', 'base_url' => 'https://other.example.test']);
    $this->actingAs($this->user);
    foreach (['seo.index', 'seo.status'] as $name) {
        $this->getJson(route($name, [...$this->range, 'website_id' => $other->id]))->assertForbidden();
    }
    $this->postJson(route('seo.refresh'), [...$this->range, 'website_id' => $other->id])->assertForbidden();
    $detail = [...$this->range, 'website_id' => $other->id, 'page_url' => $this->pageUrl];
    $this->getJson(route('seo.page', $detail))->assertForbidden();
    $this->postJson(route('seo.page.refresh'), $detail)->assertForbidden();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

test('SEO queue reuses the bounded dedicated worker and deduplicates refreshes', function () {
    $this->actingAs($this->user)->postJson(route('seo.refresh'), $this->range)->assertStatus(202);
    $this->postJson(route('seo.refresh'), $this->range)->assertStatus(202);
    Queue::assertPushed(SeoRefreshReport::class, 1);
    Queue::assertPushed(SeoRefreshReport::class, fn ($job) => $job->connection === 'traffic' && $job->queue === 'traffic' && $job->timeout === 180 && $job->tries === 1);
    expect(SeoReport::count())->toBe(1)->and(SeoReport::first()->status)->toBe('queued');
    Http::assertNothingSent();
});

test('SEO successful cache is private and fresh reports skip duplicate work for one hour', function () {
    $report = seoLifecycleReady($this);
    expect($report->status)->toBe('ready');
    $this->actingAs($this->user)->getJson(route('seo.status', $this->range))->assertOk()
        ->assertJsonPath('reports.0.data.opportunity_count', 0)->assertJsonMissingPath('reports.0.data.page_names')
        ->assertJsonMissingPath('reports.0.cache_key')->assertJsonMissingPath('reports.0.connection_key')->assertJsonMissingPath('reports.0.mapping_key')
        ->assertDontSee('synthetic-access')->assertDontSee('synthetic-secret')->assertDontSee($this->pageUrl);
    expect(app(SeoReporting::class)->queue($this->user, $this->range))->toBe(0);
    $this->travel(61)->minutes();
    expect(app(SeoReporting::class)->queue($this->user, $this->range))->toBe(1);
    expect($report->fresh()->payload)->toBeNull();
    expect(app(SeoReporting::class)->reports($this->user, $this->range)[0]['data'])->toBeNull();
});

test('SEO failed providers never expose raw errors or zero-success data and cooldown five minutes', function () {
    app(SeoReporting::class)->queue($this->user, $this->range);
    $this->mock(SeoGoogleReports::class, fn ($mock) => $mock->shouldReceive('report')->once()->andThrow(new RuntimeException('secret-token private@example.test provider-body')));
    $report = seoLifecycleRun($this);
    expect($report->status)->toBe('failed')->and($report->payload)->toBeNull()
        ->and($report->error)->not->toContain('secret-token', 'private@example.test', 'provider-body');
    expect(app(SeoReporting::class)->queue($this->user, $this->range))->toBe(0);
    $this->travel(6)->minutes();
    expect(app(SeoReporting::class)->queue($this->user, $this->range))->toBe(1);
});

test('SEO one failed website does not hide another successful website', function () {
    $other = Website::create(['user_id' => $this->user->id, 'name' => 'Second', 'slug' => 'seo-second', 'base_url' => 'https://second.example.test']);
    TrafficWebsite::create([
        'user_id' => $this->user->id, 'website_id' => $other->id, 'connection_key' => $this->connection->connection_key,
        'mapping_key' => (string) Str::uuid(), 'gsc_site_url' => 'sc-domain:second.example.test',
    ]);
    app(SeoReporting::class)->queue($this->user, $this->range);
    $this->mock(SeoGoogleReports::class, function ($mock) {
        $mock->shouldReceive('report')->once()->withArgs(fn ($connection, $site) => $site === 'sc-domain:seo.example.test')->andReturn(seoLifecyclePayload());
        $mock->shouldReceive('report')->once()->withArgs(fn ($connection, $site) => $site === 'sc-domain:second.example.test')->andThrow(new RuntimeException('private'));
    });
    foreach (SeoReport::all() as $report) {
        seoLifecycleRun($this, $report);
    }
    $reports = collect(app(SeoReporting::class)->reports($this->user, $this->range))->keyBy('website_id');
    expect($reports[$this->website->id]['status'])->toBe('ready')->and($reports[$this->website->id]['data'])->toBeArray()
        ->and($reports[$other->id]['status'])->toBe('failed')->and($reports[$other->id]['data'])->toBeNull();
});

test('SEO connection mapping credentials and ownership changes remove cached visibility', function (string $change) {
    seoLifecycleReady($this);
    match ($change) {
        'disconnect' => $this->connection->delete(),
        'connection' => $this->connection->update(['connection_key' => (string) Str::uuid()]),
        'mapping' => $this->mapping->update(['mapping_key' => (string) Str::uuid()]),
        'site' => $this->mapping->update(['gsc_site_url' => 'sc-domain:changed.example.test']),
        'credentials' => TrafficAppSetting::find(1)->update(['client_secret' => 'changed']),
        'ownership' => $this->website->update(['user_id' => User::factory()->create()->id]),
    };
    $reports = app(SeoReporting::class)->reports($this->user, $this->range);
    if ($change === 'ownership') {
        expect($reports)->toBe([]);
    } else {
        expect($reports[0]['data'])->toBeNull();
    }
})->with(['disconnect', 'connection', 'mapping', 'site', 'credentials', 'ownership']);

test('SEO expired authorization stays visible as reconnect required without cached results', function () {
    seoLifecycleReady($this);
    $this->connection->update(['reconnect_required' => true]);
    $this->actingAs($this->user)->getJson(route('seo.status', $this->range))->assertOk()->assertJsonPath('reports.0.status', 'failed')
        ->assertJsonPath('reports.0.data', null)->assertJsonPath('reports.0.updated_at', null)->assertSee('Reconnect your reporting account');
    $this->get(route('seo.index', $this->range))->assertInertia(fn (Assert $page) => $page
        ->where('seo.connection.connected', false)->where('seo.connection.reconnect_required', true)->where('seo.reports.0.data', null));
    $this->postJson(route('seo.refresh'), $this->range)->assertStatus(409);
    Http::assertNothingSent();
});

test('SEO obsolete jobs skip the external call and cannot reintroduce an old mapping', function () {
    app(SeoReporting::class)->queue($this->user, $this->range);
    $this->mapping->update(['mapping_key' => (string) Str::uuid()]);
    $this->mock(SeoGoogleReports::class, fn ($mock) => $mock->shouldNotReceive('report'));
    $report = seoLifecycleRun($this);
    expect($report->status)->toBe('failed')->and($report->payload)->toBeNull();
    Http::assertNothingSent();
});

test('SEO identity changes during provider calls discard results', function (string $change) {
    app(SeoReporting::class)->queue($this->user, $this->range);
    $this->mock(SeoGoogleReports::class, function ($mock) use ($change) {
        $mock->shouldReceive('report')->once()->andReturnUsing(function () use ($change) {
            match ($change) {
                'mapping' => $this->mapping->update(['mapping_key' => (string) Str::uuid()]),
                'ownership' => $this->website->update(['user_id' => User::factory()->create()->id]),
                'connection' => $this->connection->update(['connection_key' => (string) Str::uuid()]),
            };

            return seoLifecyclePayload([$this->pageUrl]);
        });
    });
    $report = seoLifecycleRun($this);
    expect($report->status)->toBe('failed')->and($report->payload)->toBeNull();
})->with(['mapping', 'ownership', 'connection']);

test('SEO duplicate and superseded generation jobs cannot clobber newer work', function () {
    app(SeoReporting::class)->queue($this->user, $this->range);
    $report = SeoReport::firstOrFail();
    $oldKey = $report->run_key;
    $this->travel(15)->minutes();
    expect(app(SeoReporting::class)->reports($this->user, $this->range)[0]['status'])->toBe('failed');
    expect(app(SeoReporting::class)->queue($this->user, $this->range))->toBe(1);
    $oldJob = new SeoRefreshReport($report->id, $oldKey);
    $oldJob->handle(app(SeoReporting::class));
    $oldJob->failed(new RuntimeException('private'));
    expect($report->fresh()->status)->toBe('queued')->and($report->fresh()->run_key)->not->toBe($oldKey);
    $this->mock(SeoGoogleReports::class, fn ($mock) => $mock->shouldReceive('report')->once()->andReturn(seoLifecyclePayload()));
    $newJob = new SeoRefreshReport($report->id, $report->fresh()->run_key);
    $newJob->handle(app(SeoReporting::class));
    $newJob->handle(app(SeoReporting::class));
    expect($report->fresh()->status)->toBe('ready');
});

test('SEO admin account does not inherit another users connection or report cache', function () {
    seoLifecycleReady($this);
    $admin = User::factory()->create(['is_admin' => true]);
    $this->actingAs($admin)->getJson(route('seo.status', $this->range))->assertOk()->assertJsonPath('reports.0.status', 'unlinked')->assertJsonPath('reports.0.data', null);
    $this->postJson(route('seo.refresh'), $this->range)->assertStatus(409);
});

test('SEO GA4-only mappings stay unlinked and enqueue no SEO work', function () {
    $this->mapping->update(['gsc_site_url' => null]);
    expect(app(SeoReporting::class)->queue($this->user, $this->range))->toBe(0);
    expect(app(SeoReporting::class)->reports($this->user, $this->range)[0]['status'])->toBe('unlinked');
    Queue::assertNothingPushed();
});

test('SEO detail GET is local missing before explicit POST and detail identity is independent', function () {
    seoLifecycleReady($this);
    $detail = [...$this->range, 'website_id' => $this->website->id, 'page_url' => $this->pageUrl];
    $this->actingAs($this->user)->getJson(route('seo.page', $detail))->assertOk()->assertJsonPath('report.status', 'missing')->assertJsonPath('report.data', null)
        ->assertHeader('Cache-Control', 'no-store, private');
    expect(SeoReport::count())->toBe(1);
    Queue::assertPushed(SeoRefreshReport::class, 1);
    $this->postJson(route('seo.page.refresh'), $detail)->assertStatus(202);
    $this->postJson(route('seo.page.refresh'), $detail)->assertStatus(202);
    Queue::assertPushed(SeoRefreshReport::class, 2);
    expect(SeoReport::count())->toBe(2);
    $this->getJson(route('seo.page', $detail))->assertOk()->assertJsonPath('report.status', 'queued');
    $this->mock(SeoGoogleReports::class, function ($mock) {
        $mock->shouldReceive('report')->once()->withArgs(fn ($connection, $site, $start, $end, $page) => $page === $this->pageUrl && $start === $this->range['start_date'])
            ->andReturn([...seoLifecyclePayload(), 'queries' => [['name' => 'synthetic query', 'current' => ['clicks' => 5, 'impressions' => 150, 'ctr' => 5 / 150, 'position' => 12], 'previous' => null, 'click_change' => null]]]);
    });
    seoLifecycleRun($this, SeoReport::whereNotNull('page_url')->firstOrFail());
    $this->getJson(route('seo.page', $detail))->assertOk()->assertJsonPath('report.status', 'ready')->assertJsonPath('report.data.queries.0.name', 'synthetic query')
        ->assertJsonMissingPath('report.data.page_names');
    Http::assertNothingSent();
});

test('SEO detail rejects invalid URL forms and requires a selected website', function (array $input) {
    $detail = [...$this->range, 'website_id' => $this->website->id, 'page_url' => $this->pageUrl, ...$input];
    $this->actingAs($this->user)->getJson(route('seo.page', $detail))->assertUnprocessable();
    $this->postJson(route('seo.page.refresh'), $detail)->assertUnprocessable();
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([
    [['website_id' => null]], [['page_url' => 'javascript:alert(1)']], [['page_url' => 'ftp://seo.example.test/file']],
    [['page_url' => 'https://name:password@seo.example.test/booking']], [['page_url' => 'https://name@seo.example.test/booking']],
    [['page_url' => ['https://seo.example.test/booking']]], [['page_url' => 'https://seo.example.test/'.str_repeat('a', 2048)]],
]);

test('SEO detail rejects pages absent from current overview and does not infer URL membership', function (string $page) {
    seoLifecycleReady($this);
    $detail = [...$this->range, 'website_id' => $this->website->id, 'page_url' => $page];
    $this->actingAs($this->user)->getJson(route('seo.page', $detail))->assertStatus(409);
    $this->postJson(route('seo.page.refresh'), $detail)->assertStatus(409);
    expect(SeoReport::count())->toBe(1);
    Http::assertNothingSent();
})->with(['https://evil.example.test/booking', 'https://seo.example.test/booking/', 'https://seo.example.test/booking?different=1']);

test('SEO detail cannot reuse a returned page from another date range', function () {
    seoLifecycleReady($this);
    $detail = [...$this->range, 'website_id' => $this->website->id, 'page_url' => $this->pageUrl, 'start_date' => '2026-09-02'];
    $this->actingAs($this->user)->getJson(route('seo.page', $detail))->assertStatus(409);
    $this->postJson(route('seo.page.refresh'), $detail)->assertStatus(409);
});

test('SEO accepts exact Unicode or uppercase-scheme pages without fetching those URLs', function (string $pageUrl) {
    $this->pageUrl = $pageUrl;
    seoLifecycleReady($this);
    $detail = [...$this->range, 'website_id' => $this->website->id, 'page_url' => $this->pageUrl];
    $this->actingAs($this->user)->getJson(route('seo.page', $detail))->assertOk()->assertJsonPath('report.status', 'missing');
    $this->postJson(route('seo.page.refresh'), $detail)->assertStatus(202);
    expect(SeoReport::whereNotNull('page_url')->firstOrFail()->page_url)->toBe($this->pageUrl);
    Http::assertNothingSent();
})->with(['https://réservation.example.test/pré-réservation', 'HTTPS://seo.example.test/booking']);

test('SEO different eligible pages keep independent detail cache identities', function () {
    $overview = seoLifecycleReady($this);
    $otherPage = 'https://seo.example.test/hotel';
    $overview->update(['payload' => seoLifecyclePayload([$this->pageUrl, $otherPage])]);
    $filters = [...$this->range, 'website_id' => $this->website->id];
    expect(app(SeoReporting::class)->queue($this->user, $filters, $this->pageUrl))->toBe(1)
        ->and(app(SeoReporting::class)->queue($this->user, $filters, $otherPage))->toBe(1);
    expect(SeoReport::whereNotNull('page_url')->count())->toBe(2)
        ->and(SeoReport::pluck('cache_key')->unique()->count())->toBe(3);
});

test('SEO authorization expiring during analysis persists reconnect state without exposing payload', function () {
    app(SeoReporting::class)->queue($this->user, $this->range);
    $this->mock(SeoGoogleReports::class, function ($mock) {
        $mock->shouldReceive('report')->once()->andReturnUsing(function () {
            $this->connection->update(['reconnect_required' => true]);
            throw new RuntimeException('invalid_grant provider private-body');
        });
    });
    $report = seoLifecycleRun($this);
    expect($this->connection->fresh()->reconnect_required)->toBeTrue()->and($report->payload)->toBeNull();
    $this->actingAs($this->user)->getJson(route('seo.status', $this->range))->assertOk()
        ->assertJsonPath('reports.0.status', 'failed')->assertJsonPath('reports.0.data', null)->assertSee('Reconnect your reporting account')
        ->assertDontSee('provider private-body');
});

test('SEO cached detail is hidden when current overview no longer returns its page', function () {
    $overview = seoLifecycleReady($this);
    app(SeoReporting::class)->queue($this->user, [...$this->range, 'website_id' => $this->website->id], $this->pageUrl);
    SeoReport::whereNotNull('page_url')->firstOrFail()->update(['status' => 'ready', 'payload' => seoLifecyclePayload(), 'refreshed_at' => now()]);
    $overview->update(['payload' => seoLifecyclePayload()]);
    $this->actingAs($this->user)->getJson(route('seo.page', [...$this->range, 'website_id' => $this->website->id, 'page_url' => $this->pageUrl]))
        ->assertStatus(409)->assertJsonMissingPath('report.data');
});

test('SEO queued detail checks current overview membership before and after provider call', function (string $moment) {
    $overview = seoLifecycleReady($this);
    app(SeoReporting::class)->queue($this->user, [...$this->range, 'website_id' => $this->website->id], $this->pageUrl);
    $this->mock(SeoGoogleReports::class, function ($mock) use ($moment, $overview) {
        if ($moment === 'before') {
            $overview->update(['payload' => seoLifecyclePayload()]);
            $mock->shouldNotReceive('report');
        } else {
            $mock->shouldReceive('report')->once()->andReturnUsing(function () use ($overview) {
                $overview->update(['payload' => seoLifecyclePayload()]);

                return seoLifecyclePayload();
            });
        }
    });
    $detail = seoLifecycleRun($this, SeoReport::whereNotNull('page_url')->firstOrFail());
    expect($detail->status)->toBe('failed')->and($detail->payload)->toBeNull();
})->with(['before', 'after']);

test('SEO refreshing overview suspends eligibility from the prior snapshot', function () {
    seoLifecycleReady($this);
    $this->travel(61)->minutes();
    app(SeoReporting::class)->queue($this->user, $this->range);
    $this->actingAs($this->user)->getJson(route('seo.page', [...$this->range, 'website_id' => $this->website->id, 'page_url' => $this->pageUrl]))->assertStatus(409);
});

test('SEO timed out jobs clear metrics and report only sanitized errors', function () {
    app(SeoReporting::class)->queue($this->user, $this->range);
    $report = SeoReport::firstOrFail();
    $report->update(['status' => 'running', 'payload' => seoLifecyclePayload([$this->pageUrl])]);
    (new SeoRefreshReport($report->id, $report->run_key))->failed(new RuntimeException('private-body'));
    expect($report->fresh()->status)->toBe('failed')->and($report->fresh()->payload)->toBeNull()
        ->and($report->fresh()->error)->not->toContain('private-body');
});

test('SEO snapshots join bounded daily pruning without deleting recent results', function () {
    app(SeoReporting::class)->queue($this->user, $this->range);
    $old = SeoReport::firstOrFail();
    $old->updated_at = now()->subDays(31);
    $old->save(['timestamps' => false]);
    app(SeoReporting::class)->queue($this->user, [...$this->range, 'start_date' => '2026-09-02']);
    $this->artisan('traffic:prune-reports')->assertSuccessful();
    expect(SeoReport::count())->toBe(1)->and(SeoReport::find($old->id))->toBeNull();
});

test('SEO status polling does not consume overview or detail refresh limits', function () {
    seoLifecycleReady($this);
    $detail = [...$this->range, 'website_id' => $this->website->id, 'page_url' => $this->pageUrl];
    $this->actingAs($this->user);
    foreach (range(1, 7) as $ignored) {
        $this->getJson(route('seo.status', $this->range))->assertOk();
        $this->getJson(route('seo.page', $detail))->assertOk();
    }
    $this->postJson(route('seo.refresh'), $this->range)->assertStatus(202);
    $this->postJson(route('seo.page.refresh'), $detail)->assertStatus(202);
    Queue::assertPushed(SeoRefreshReport::class, 2);
});
