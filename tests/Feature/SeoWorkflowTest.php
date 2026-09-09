<?php

use App\Jobs\SeoRefreshReport;
use App\Models\SeoReport;
use App\Models\TrafficAppSetting;
use App\Models\TrafficConnection;
use App\Models\TrafficWebsite;
use App\Models\User;
use App\Models\Website;
use App\Services\GoogleReportingClient;
use App\Services\SeoReporting;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

test('SEO workflow queues real provider analysis and then serves exact page queries from local snapshots', function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    $this->travelTo(now()->setDate(2026, 9, 9)->setTime(12, 0));
    Http::preventStrayRequests();
    Queue::fake();
    $user = User::factory()->create();
    $website = Website::create(['user_id' => $user->id, 'name' => 'SEO workflow demo', 'slug' => 'seo-workflow-demo', 'base_url' => 'https://seo.example.test']);
    TrafficAppSetting::create(['id' => 1, 'client_id' => 'reporting.apps.googleusercontent.com', 'client_secret' => 'synthetic-secret']);
    $connection = TrafficConnection::create([
        'user_id' => $user->id, 'connection_key' => (string) Str::uuid(), 'app_fingerprint' => app(GoogleReportingClient::class)->fingerprint(),
        'email' => 'reporting@example.test', 'access_token' => 'synthetic-access', 'refresh_token' => 'synthetic-refresh',
        'expires_at' => now()->addHour(), 'connected_at' => now(),
    ]);
    TrafficWebsite::create([
        'user_id' => $user->id, 'website_id' => $website->id, 'connection_key' => $connection->connection_key,
        'mapping_key' => (string) Str::uuid(), 'gsc_site_url' => 'sc-domain:seo.example.test',
    ]);
    $range = ['website_id' => $website->id, 'start_date' => '2026-08-10', 'end_date' => '2026-09-06'];
    $pageUrl = 'https://seo.example.test/flight-reservation';
    $detail = [...$range, 'page_url' => $pageUrl];
    Http::fake(['www.googleapis.com/webmasters/v3/sites/*' => function (Request $request) use ($pageUrl) {
        $previous = $request['endDate'] === '2026-08-09';
        $byPage = $request['dimensions'] === ['page'];
        $filtered = isset($request['dimensionFilterGroups']);
        if ($filtered) {
            expect($request['dimensionFilterGroups'])->toBe([['groupType' => 'and', 'filters' => [
                ['dimension' => 'page', 'operator' => 'equals', 'expression' => $pageUrl],
            ]]]);
        }
        $clicks = $previous ? 30 : 10;
        $rows = [['keys' => [$byPage ? $pageUrl : 'flight reservation for visa'], 'clicks' => $clicks, 'impressions' => 1000, 'ctr' => $clicks / 1000, 'position' => $byPage ? 6 : 12]];
        if (! $byPage && ! $previous) {
            $rows[] = ['keys' => ['newly returned query'], 'clicks' => 0, 'impressions' => 200, 'ctr' => 0, 'position' => 15];
        }

        return Http::response(['rows' => $rows, 'responseAggregationType' => $byPage || $filtered ? 'byPage' : 'byProperty']);
    }]);

    $this->actingAs($user)->postJson(route('seo.refresh'), $range)->assertStatus(202);
    Http::assertNothingSent();
    Queue::assertPushed(SeoRefreshReport::class, 1);
    $snapshot = SeoReport::firstOrFail();
    (new SeoRefreshReport($snapshot->id, $snapshot->run_key))->handle(app(SeoReporting::class));
    Http::assertSentCount(4);
    $overview = $this->getJson(route('seo.status', $range))->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('reports.0.status', 'ready')->assertJsonPath('reports.0.data.opportunity_count', 5)
        ->assertJsonMissingPath('reports.0.data.page_names')->json('reports.0.data');
    expect($overview['previous_start_date'])->toBe('2026-07-13')
        ->and(collect($overview['opportunities'])->where('name', 'newly returned query')->pluck('type')->all())->toBe(['near_page_one']);

    $this->getJson(route('seo.page', $detail))->assertOk()->assertJsonPath('report.status', 'missing');
    Http::assertSentCount(4);
    $this->postJson(route('seo.page.refresh'), $detail)->assertStatus(202);
    Queue::assertPushed(SeoRefreshReport::class, 2);
    $pageSnapshot = SeoReport::whereNot('id', $snapshot->id)->firstOrFail();
    (new SeoRefreshReport($pageSnapshot->id, $pageSnapshot->run_key))->handle(app(SeoReporting::class));
    Http::assertSentCount(6);
    $this->getJson(route('seo.page', $detail))->assertOk()->assertJsonPath('report.status', 'ready')
        ->assertJsonPath('report.data.queries.0.click_change', -20)
        ->assertJsonPath('report.data.queries.1.previous', null)->assertJsonPath('report.data.queries.1.click_change', null)
        ->assertJsonMissingPath('report.data.page_names');
    $this->postJson(route('seo.page.refresh'), $detail)->assertStatus(202);
    Queue::assertPushed(SeoRefreshReport::class, 2);
    Http::assertSentCount(6);
    $this->getJson(route('seo.page', [...$detail, 'page_url' => 'https://other.example.test/private']))->assertStatus(409);
    Http::assertSentCount(6);
});
