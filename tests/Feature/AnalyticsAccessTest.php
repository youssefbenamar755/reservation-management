<?php

use App\Models\FfSubmission;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::fake();

    // The application uses MySQL date functions. Supply their equivalents for
    // these in-memory SQLite integration tests; all queries still execute.
    if (DB::getDriverName() === 'sqlite') {
        DB::connection()->getPdo()->sqliteCreateFunction('HOUR', fn ($date) => Carbon::parse($date)->hour);
        DB::connection()->getPdo()->sqliteCreateFunction('DAYOFWEEK', fn ($date) => Carbon::parse($date)->dayOfWeek + 1);
    }
});

function analyticsWebsite(User $user, string $slug, float $total = 20, string $origin = 'CMN'): Website
{
    $website = Website::create([
        'user_id' => $user->id,
        'name' => $slug,
        'slug' => $slug,
        'base_url' => 'https://'.$slug.'.example.com',
        'status' => 'active',
    ]);

    WcOrder::create([
        'website_id' => $website->id,
        'wp_order_id' => 1,
        'status' => 'completed',
        'total' => $total,
        'created_at_wp' => now(),
        'payload' => ['billing' => ['country' => 'MA']],
    ]);

    FfSubmission::create([
        'website_id' => $website->id,
        'form_id' => 1,
        'entry_id' => 1,
        'created_at_wp' => now(),
        'payload' => ['response' => ['flight_from' => $origin, 'flight_to' => 'CDG']],
    ]);

    return $website;
}

test('analytics scopes all order and submission metrics to owned websites', function () {
    $user = User::factory()->create();
    analyticsWebsite($user, 'owned');
    analyticsWebsite(User::factory()->create(), 'foreign', 1000, 'JFK');

    $this->actingAs($user)->get(route('analytics.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Analytics')
            ->where('stats.total_orders', 1)
            ->where('stats.total_revenue', 20)
            ->where('conversionFunnel.form_submissions', 1)
            ->has('topDepartureAirports', 1)
            ->where('topDepartureAirports.0.airport', 'CMN')
            ->where('topArrivalAirports.0.count', 1)
            ->has('topRoutes', 1)
            ->where('topRoutes.0.count', 1)
            ->has('websites', 1));

    Http::assertNothingSent();
});

test('filtering analytics to an unowned website returns no data', function () {
    $user = User::factory()->create();
    analyticsWebsite($user, 'owned');
    $foreign = analyticsWebsite(User::factory()->create(), 'foreign', 1000, 'JFK');

    $this->actingAs($user)->get(route('analytics.index', ['website_ids' => [$foreign->id]]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_orders', 0)
            ->where('conversionFunnel.form_submissions', 0)
            ->has('topDepartureAirports', 0)
            ->has('topArrivalAirports', 0)
            ->has('topRoutes', 0));
});

test('analytics status filters work with website joins and exclude other statuses', function () {
    $user = User::factory()->create();
    $website = analyticsWebsite($user, 'owned');
    WcOrder::create([
        'website_id' => $website->id,
        'wp_order_id' => 2,
        'status' => 'pending',
        'total' => 100,
        'created_at_wp' => now(),
        'payload' => [],
    ]);

    $this->actingAs($user)->get(route('analytics.index', ['payment_status' => 'completed']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_orders', 1)
            ->where('stats.total_revenue', 20)
            ->has('revenueByWebsite', 1)
            ->has('websitePerformance', 1));
});

test('administrators retain analytics access across websites', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    analyticsWebsite($admin, 'admin');
    analyticsWebsite(User::factory()->create(), 'other', 80, 'JFK');

    $this->actingAs($admin)->get(route('analytics.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_orders', 2)
            ->where('stats.total_revenue', 100)
            ->where('conversionFunnel.form_submissions', 2)
            ->has('topDepartureAirports', 2)
            ->has('websites', 2));
});

test('a user without websites receives empty analytics', function () {
    analyticsWebsite(User::factory()->create(), 'foreign');

    $this->actingAs(User::factory()->create())->get(route('analytics.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_orders', 0)
            ->where('conversionFunnel.form_submissions', 0)
            ->has('topRoutes', 0)
            ->has('websites', 0));
});

test('revoked website ownership cannot reuse cached analytics', function () {
    $user = User::factory()->create();
    $website = analyticsWebsite($user, 'owned');
    $this->actingAs($user)->get(route('analytics.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('stats.total_orders', 1));

    $website->update(['user_id' => User::factory()->create()->id]);

    $this->get(route('analytics.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('stats.total_orders', 0)
            ->where('conversionFunnel.form_submissions', 0)
            ->has('topRoutes', 0)
            ->has('websites', 0));
});

test('analytics uses cached country enrichment without calling an external API', function () {
    $user = User::factory()->create();
    $website = analyticsWebsite($user, 'owned');
    $website->wcOrders()->first()->update(['payload' => ['customer_ip_address' => '8.8.8.8']]);
    Cache::put('ip_country_8.8.8.8', 'US', 3600);

    $this->actingAs($user)->get(route('analytics.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('ordersByCountry.0.country', 'US')
            ->where('topCountry.country', 'US'));

    Http::assertNothingSent();
});

test('missing country enrichment never delays analytics with HTTP requests', function () {
    $user = User::factory()->create();
    $website = analyticsWebsite($user, 'owned');
    $website->wcOrders()->first()->update(['payload' => ['customer_ip_address' => '8.8.4.4']]);

    $this->actingAs($user)->get(route('analytics.index'))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('ordersByCountry', 0)
            ->where('topCountry', null)
            ->where('stats.total_orders', 1));

    Http::assertNothingSent();
});
