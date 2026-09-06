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
    Http::preventStrayRequests();
    $this->travelTo(Carbon::parse('2026-09-06 12:00:00'));
    if (DB::getDriverName() === 'sqlite') {
        DB::connection()->getPdo()->sqliteCreateFunction('HOUR', fn ($date) => Carbon::parse($date)->hour);
        DB::connection()->getPdo()->sqliteCreateFunction('DAYOFWEEK', fn ($date) => Carbon::parse($date)->dayOfWeek + 1);
    }
    $this->owner = User::factory()->create();
    $createWebsite = fn (User $user, string $name) => Website::create([
        'user_id' => $user->id, 'name' => $name, 'slug' => strtolower($name),
        'base_url' => 'https://'.strtolower($name).'.example.test',
    ]);
    $this->firstWebsite = $createWebsite($this->owner, 'First');
    $this->secondWebsite = $createWebsite($this->owner, 'Second');
    $this->foreignWebsite = $createWebsite(User::factory()->create(), 'Foreign');
    $createOrder = function (Website $website, int $id, string $date, string $status, float $total, array $payload = []) {
        WcOrder::create([
            'website_id' => $website->id, 'wp_order_id' => $id, 'created_at_wp' => $date,
            'status' => $status, 'total' => $total, 'currency' => 'USD', 'payload' => $payload,
        ]);
    };
    $fee = fn ($amount) => ['meta_data' => [['key' => '_ppcp_paypal_fees', 'value' => ['paypal_fee' => ['value' => $amount]]]]];
    $createOrder($this->firstWebsite, 1, '2026-09-01 10:15:00', 'completed', 100, ['billing' => ['country' => 'MA']] + $fee('3.50'));
    $createOrder($this->firstWebsite, 2, '2026-09-02 18:00:00', 'completed', 40, ['customer_ip_address' => '8.8.8.8'] + $fee(2));
    $createOrder($this->firstWebsite, 3, '2026-09-02 18:00:00', 'pending', 30, ['customer_ip_address' => '8.8.4.4'] + $fee(99));
    $createOrder($this->secondWebsite, 4, '2026-09-02 18:00:00', 'completed', 60, ['billing' => ['country' => 'US']] + $fee('invalid'));
    $createOrder($this->secondWebsite, 5, '2026-09-01 10:00:00', 'cancelled', 20, ['billing' => ['country' => 'MA']]);
    $createOrder($this->firstWebsite, 6, '2026-08-31 12:00:00', 'completed', 50);
    $createOrder($this->secondWebsite, 7, '2026-08-31 12:00:00', 'completed', 20);
    $createOrder($this->firstWebsite, 8, '2026-09-03 12:00:00', 'completed', 500);
    $createOrder($this->foreignWebsite, 9, '2026-09-01 12:00:00', 'completed', 999);
    Cache::put('ip_country_8.8.8.8', ' us ', 3600);

    $createSubmission = function (Website $website, int $id, array $payload, string $date = '2026-09-02 12:00:00') {
        FfSubmission::create([
            'website_id' => $website->id, 'form_id' => 1, 'entry_id' => $id,
            'created_at_wp' => $date, 'payload' => $payload,
        ]);
    };
    $createSubmission($this->firstWebsite, 1, ['response' => ['flight_from' => 'Casablanca (CMN)', 'flight_to' => 'CDG']]);
    $createSubmission($this->firstWebsite, 2, ['data' => ['itineraries' => [
        ['segments' => [
            ['departure' => ['iataCode' => 'JFK'], 'arrival' => ['iataCode' => 'LHR']],
            ['departure' => ['iataCode' => 'LHR'], 'arrival' => ['iataCode' => 'CDG']],
        ]],
        ['segments' => [['departure' => ['iataCode' => 'CDG'], 'arrival' => ['iataCode' => 'JFK']]]],
    ]]]);
    $createSubmission($this->secondWebsite, 3, ['response' => ['flight_from' => ['MAD'], 'flight_to' => [['Rome (FCO)']]]]);
    $createSubmission($this->secondWebsite, 4, ['response' => ['email' => 'customer@example.test']]);
    $createSubmission($this->foreignWebsite, 5, ['response' => ['flight_from' => 'LAX', 'flight_to' => 'HNL']]);
    $createSubmission($this->firstWebsite, 6, ['response' => ['flight_from' => 'LAX', 'flight_to' => 'HNL']], '2026-08-31 12:00:00');
});

test('analytics preserves all financial country flight and comparison metrics', function () {
    $this->actingAs($this->owner)->get(route('analytics.index', ['start_date' => '2026-09-01', 'end_date' => '2026-09-02']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('stats.total_orders', 5)
        ->where('stats.paid_orders', 3)
        ->where('stats.total_revenue', 200)
        ->where('stats.paypal_fees', 5.5)
        ->where('stats.net_revenue', 194.5)
        ->where('stats.average_order_value', fn ($value) => abs($value - 200 / 3) < 0.000001)
        ->where('stats.fee_percentage', 2.75)
        ->where('stats.revenue_growth_percent', fn ($value) => abs($value - (200 - 70) / 70 * 100) < 0.000001)
        ->where('stats.orders_growth_percent', 150)
        ->where('revenueOverTime', [['date' => '2026-09-01', 'revenue' => 100], ['date' => '2026-09-02', 'revenue' => 100]])
        ->where('ordersOverTime', [['date' => '2026-09-01', 'count' => 2], ['date' => '2026-09-02', 'count' => 3]])
        ->where('revenueByWebsite', [
            ['id' => $this->firstWebsite->id, 'name' => 'First', 'revenue' => 140],
            ['id' => $this->secondWebsite->id, 'name' => 'Second', 'revenue' => 60],
        ])
        ->where('ordersByCountry', [['country' => 'MA', 'count' => 2], ['country' => 'US', 'count' => 2]])
        ->where('topCountry', ['country' => 'MA', 'revenue' => 100, 'percentage' => 50])
        ->where('ordersByHour', [['hour' => 10, 'count' => 2], ['hour' => 18, 'count' => 3]])
        ->where('ordersByDayOfWeek', [
            ['day' => 'Tuesday', 'day_number' => 3, 'count' => 2],
            ['day' => 'Wednesday', 'day_number' => 4, 'count' => 3],
        ])
        ->where('peakOrderTime', ['hour' => '6:00 PM', 'day' => 'Wednesday'])
        ->where('websitePerformance.0.orders', 3)
        ->where('websitePerformance.0.revenue', 140)
        ->where('websitePerformance.0.aov', fn ($value) => abs($value - 140 / 3) < 0.000001)
        ->where('websitePerformance.0.growth_percent', 180)
        ->where('websitePerformance.1.orders', 2)
        ->where('websitePerformance.1.aov', 30)
        ->where('websitePerformance.1.growth_percent', 200)
        ->where('conversionFunnel', [
            'form_submissions' => 4, 'orders_created' => 5, 'paid_orders' => 3,
            'submission_to_order_rate' => 125, 'order_to_paid_rate' => 60, 'submission_to_paid_rate' => 75,
        ])
        ->where('topDepartureAirports', [
            ['airport' => 'CMN', 'count' => 1], ['airport' => 'JFK', 'count' => 1],
            ['airport' => 'CDG', 'count' => 1], ['airport' => 'MAD', 'count' => 1],
        ])
        ->where('topArrivalAirports', [['airport' => 'CDG', 'count' => 2], ['airport' => 'JFK', 'count' => 1], ['airport' => 'FCO', 'count' => 1]])
        ->where('topRoutes', [
            ['route' => 'CMN → CDG', 'count' => 1], ['route' => 'JFK → CDG', 'count' => 1],
            ['route' => 'CDG → JFK', 'count' => 1], ['route' => 'MAD → FCO', 'count' => 1],
        ])
        ->has('websites', 2));
    Http::assertNothingSent();
});

test('analytics comma separated website filters and order status do not alter submission metrics', function () {
    $this->actingAs($this->owner)->get(route('analytics.index', [
        'start_date' => '2026-09-01', 'end_date' => '2026-09-02',
        'website_ids' => $this->firstWebsite->id.','.$this->foreignWebsite->id,
        'payment_status' => 'pending',
    ]))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('stats.total_orders', 1)
        ->where('stats.total_revenue', 0)
        ->where('stats.paypal_fees', 0)
        ->where('conversionFunnel.form_submissions', 2)
        ->has('topRoutes', 3)
        ->has('ordersByCountry', 0)
        ->has('revenueOverTime', 0)
        ->has('revenueByWebsite', 0)
        ->where('websitePerformance.0.orders', 1));
    Http::assertNothingSent();
});
