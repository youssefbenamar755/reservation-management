<?php

use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->other = User::factory()->create();
    $this->site = Website::create(['user_id' => $this->owner->id, 'name' => 'First site', 'slug' => 'first-site', 'base_url' => 'https://first.example']);
    $this->second = Website::create(['user_id' => $this->owner->id, 'name' => 'Second site', 'slug' => 'second-site', 'base_url' => 'https://second.example']);
    $this->foreign = Website::create(['user_id' => $this->other->id, 'name' => 'Foreign site', 'slug' => 'foreign-site', 'base_url' => 'https://foreign.example']);
});

function createExportOrder(Website $site, array $values = []): WcOrder
{
    static $id = 0;

    return WcOrder::create(array_merge([
        'website_id' => $site->id, 'wp_order_id' => ++$id,
        'customer_email' => 'shared@example.com', 'customer_name' => 'Synthetic Customer',
        'status' => 'completed', 'currency' => 'USD', 'total' => 100,
        'created_at_wp' => '2026-09-06 12:00:00', 'payload' => ['billing' => ['country' => 'MA']],
    ], $values));
}

function parseCustomerCsv(string $content): array
{
    expect($content)->toStartWith("\xEF\xBB\xBF");
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, substr($content, 3));
    rewind($stream);
    $rows = [];
    while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
        $rows[] = $row;
    }
    fclose($stream);

    return $rows;
}

test('customer export requires authentication and has its own route before customer email', function () {
    $this->get('/customers/export')->assertRedirect(route('login'));
    $response = $this->actingAs($this->owner)->get('/customers/export')->assertOk();
    expect(parseCustomerCsv($response->streamedContent()))->toHaveCount(1);
    expect($response->headers->get('Content-Type'))->toBe('text/csv; charset=UTF-8');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    expect($response->headers->get('Content-Disposition'))->toContain('customers-all-websites-');
});

test('listing and export aggregate only orders matching selected websites dates status and country', function () {
    createExportOrder($this->site);
    createExportOrder($this->site, ['status' => 'pending', 'total' => 20, 'created_at_wp' => '2026-09-07 12:00:00']);
    createExportOrder($this->second, ['total' => 500, 'payload' => ['billing' => ['country' => 'CA']]]);
    createExportOrder($this->site, ['total' => 50, 'created_at_wp' => '2026-01-01 12:00:00']);
    createExportOrder($this->site, ['total' => 300, 'payload' => ['billing' => ['country' => 'FR']]]);
    createExportOrder($this->foreign, ['total' => 1000]);

    $filters = ['website_ids' => (string) $this->site->id, 'start_date' => '2026-09-01', 'end_date' => '2026-09-30', 'country' => 'ma', 'min_spend' => 80];
    $this->actingAs($this->owner)->get(route('customers.index', $filters))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('customers.data', 1)
            ->where('customers.data.0.orders_count', 2)
            ->where('customers.data.0.total_spent', 100)
            ->where('customers.data.0.average_order_value', 100)
            ->where('customers.data.0.websites', ['First site'])
            ->where('customers.data.0.country', 'MA')
            ->where('customers.data.0.first_order_at', '2026-09-06 12:00:00')
            ->where('customers.data.0.last_order_at', '2026-09-07 12:00:00')
            ->where('filters.website_ids', [$this->site->id])
            ->where('filters.country', 'MA')
        );
    $response = $this->get(route('customers.export', $filters))->assertOk();
    $rows = parseCustomerCsv($response->streamedContent());
    expect($rows)->toHaveCount(2);
    expect($rows[1])->toBe(['shared@example.com', '2', '100.00', '100.00', 'First site', 'MA', '2026-09-06 12:00:00', '2026-09-07 12:00:00']);
    expect($response->headers->get('Content-Disposition'))->toContain('customers-first-site-');

    foreach (['paid' => ['1', '100.00', '100.00'], 'pending' => ['1', '0.00', '0.00']] as $status => $expected) {
        $rows = parseCustomerCsv($this->get(route('customers.export', array_merge($filters, ['payment_status' => $status, 'min_spend' => 0])))->assertOk()->streamedContent());
        expect(array_slice($rows[1], 1, 3))->toBe($expected);
    }
    expect(parseCustomerCsv($this->get(route('customers.export', array_merge($filters, ['min_spend' => 101])))->assertOk()->streamedContent()))->toHaveCount(1);
    Http::assertNothingSent();
});

test('customer export obeys owner admin multiple website and foreign only scopes with shared emails', function () {
    createExportOrder($this->site);
    createExportOrder($this->second, ['total' => 200]);
    createExportOrder($this->foreign, ['total' => 900]);
    createExportOrder($this->foreign, ['customer_email' => 'private@example.com']);

    $this->actingAs($this->owner);
    foreach ([[], ['website_ids' => []], ['website_ids' => [$this->site->id, $this->second->id, $this->foreign->id]]] as $filters) {
        $rows = parseCustomerCsv($this->get(route('customers.export', $filters))->assertOk()->streamedContent());
        expect($rows)->toHaveCount(2);
        expect(array_slice($rows[1], 1, 4))->toBe(['2', '300.00', '150.00', 'First site; Second site']);
    }
    expect(parseCustomerCsv($this->get(route('customers.export', ['website_ids' => [$this->foreign->id]]))->assertOk()->streamedContent()))->toHaveCount(1);

    $rows = parseCustomerCsv($this->actingAs(User::factory()->create(['is_admin' => true]))->get(route('customers.export', ['sort_by' => 'total_spent']))->assertOk()->streamedContent());
    expect($rows)->toHaveCount(3);
    expect(array_slice($rows[1], 0, 5))->toBe(['shared@example.com', '3', '1200.00', '400.00', 'First site; Second site; Foreign site']);

    expect(parseCustomerCsv($this->actingAs(User::factory()->create())->get(route('customers.export'))->assertOk()->streamedContent()))->toHaveCount(1);
});

test('CSV streams every matching customer beyond page and batch boundaries inside a snapshot', function () {
    $orders = [];
    for ($i = 0; $i < 271; $i++) {
        $orders[] = ['website_id' => $this->site->id, 'wp_order_id' => $i + 1, 'customer_email' => sprintf('customer%03d@example.com', $i), 'status' => 'completed', 'currency' => 'USD', 'total' => 10, 'created_at_wp' => '2026-09-06 12:00:00', 'payload' => json_encode(['billing' => ['country' => 'MA']])];
    }
    WcOrder::insert($orders);
    $initialLevel = DB::transactionLevel();
    $orderLevels = [];
    $groupQueries = [];
    DB::listen(function ($query) use (&$orderLevels, &$groupQueries) {
        if (str_contains($query->sql, 'wc_orders')) {
            $orderLevels[] = DB::transactionLevel();
            if (str_contains($query->sql, 'group by')) {
                $groupQueries[] = $query->sql;
            }
        }
    });
    $rows = parseCustomerCsv($this->actingAs($this->owner)->get(route('customers.export', ['page' => 1000, 'per_page' => 1]))->assertOk()->streamedContent());
    expect($rows)->toHaveCount(272);
    expect(array_column(array_slice($rows, 1), 0))->toBe(array_column($orders, 'customer_email'));
    expect($groupQueries)->toHaveCount(2);
    expect($groupQueries[0])->toContain('limit 250');
    expect($groupQueries[1])->toContain('limit 250')->toContain('offset 250');
    expect(array_unique($orderLevels))->toBe([$initialLevel + 1]);
    expect(DB::transactionLevel())->toBe($initialLevel);
});

test('CSV preserves UTF8 quoted commas and newlines and protects spreadsheet expressions', function () {
    $this->site->update(['name' => "  =HYPERLINK(\"https://example.test\",\"Café, Montréal\")\r\nDemo"]);
    $emails = ['=SUM(1,2)', '+command', '-command', '@command', '  =command', "\tplain", "\rplain", 'élodie@example.test'];
    foreach ($emails as $email) {
        createExportOrder($this->site, ['customer_email' => $email]);
    }
    $content = $this->actingAs($this->owner)->get(route('customers.export'))->assertOk()->streamedContent();
    expect(mb_check_encoding($content, 'UTF-8'))->toBeTrue();
    $rows = parseCustomerCsv($content);
    expect($rows)->toHaveCount(count($emails) + 1);
    $exportedEmails = array_column(array_slice($rows, 1), 0);
    foreach ($emails as $email) {
        expect($exportedEmails)->toContain($email === 'élodie@example.test' ? $email : "'".$email);
    }
    foreach (array_slice($rows, 1) as $row) {
        expect($row[4])->toBe("'".$this->site->name);
    }
});

test('customer date filters support one sided ranges', function () {
    createExportOrder($this->site, ['created_at_wp' => '2026-01-01 00:00:00']);
    createExportOrder($this->site, ['created_at_wp' => '2026-09-06 23:59:59']);
    foreach ([['start_date' => '2026-09-06'], ['end_date' => '2026-01-01']] as $filters) {
        $rows = parseCustomerCsv($this->actingAs($this->owner)->get(route('customers.export', $filters))->assertOk()->streamedContent());
        expect($rows[1][1])->toBe('1');
    }
});

test('customer filters reject malformed or excessive values before querying', function (array $filters, string $field) {
    $this->actingAs($this->owner)->getJson(route('customers.index', $filters))->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'zero page size' => [['per_page' => 0], 'per_page'],
    'oversized page' => [['per_page' => 101], 'per_page'],
    'negative page' => [['page' => -1], 'page'],
    'excessive page' => [['page' => 1000001], 'page'],
    'bad sorting' => [['sort_by' => 'customer_email desc'], 'sort_by'],
    'bad direction' => [['sort_dir' => 'sideways'], 'sort_dir'],
    'negative spend' => [['min_spend' => -1], 'min_spend'],
    'excessive spend' => [['min_spend' => '1e50'], 'min_spend'],
    'invalid date' => [['start_date' => '2026-02-30'], 'start_date'],
    'reversed dates' => [['start_date' => '2026-09-10', 'end_date' => '2026-09-01'], 'end_date'],
    'invalid status' => [['payment_status' => 'refunded'], 'payment_status'],
    'invalid country' => [['country' => 'not-a-country'], 'country'],
    'invalid website' => [['website_ids' => ['x']], 'website_ids.0'],
]);
