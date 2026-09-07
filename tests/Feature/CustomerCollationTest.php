<?php

use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Services\CustomerListing;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('customer enrichment retains the database group for case accent and padding variants', function () {
    Http::preventStrayRequests();

    // Reproduce the relevant MySQL collation equivalences without changing the schema.
    // This is deliberately a narrow fixture collation, not a full MySQL implementation.
    DB::connection()->getPdo()->sqliteCreateCollation('customer_email_test', static function (string $left, string $right): int {
        $normalize = static fn (string $value): string => strtr(mb_strtolower(rtrim($value, ' ')), ['é' => 'e']);

        return strcmp($normalize($left), $normalize($right));
    });

    $owner = User::factory()->create();
    $other = User::factory()->create();
    $websites = collect();
    foreach (['First website', 'Second website', 'Third website', 'Foreign website'] as $index => $name) {
        $websites->push(Website::create([
            'user_id' => $index < 3 ? $owner->id : $other->id,
            'name' => $name,
            'slug' => 'collation-'.$index,
            'base_url' => 'https://collation-'.$index.'.example',
        ]));
    }

    foreach ([
        [0, 'jose@example.com', 10, 'MA'],
        [1, 'JOSÉ@EXAMPLE.COM', 20, 'US'],
        [2, 'jose@example.com ', 30, 'US'],
        [3, 'JOSÉ@example.com ', 900, 'CA'],
        [0, 'separate@example.com', 90, 'GB'],
    ] as $index => [$websiteIndex, $email, $total, $country]) {
        WcOrder::create([
            'website_id' => $websites[$websiteIndex]->id,
            'wp_order_id' => $index + 1,
            'customer_email' => $email,
            'customer_name' => $index === 1 ? 'Search Needle' : 'Earlier name',
            'status' => 'completed',
            'currency' => 'USD',
            'total' => $total,
            'created_at_wp' => '2026-09-06 12:00:00',
            'payload' => ['billing' => ['country' => $country]],
        ]);
    }

    $listing = app(CustomerListing::class);
    $authorizedWebsites = $listing->websites($owner);
    $source = DB::table('wc_orders')
        ->select('id', 'website_id', 'status', 'total', 'created_at_wp', 'payload', 'customer_name')
        ->selectRaw('customer_email COLLATE customer_email_test AS customer_email');
    $orders = $listing->orders($authorizedWebsites->pluck('id')->all())->fromSub($source, 'wc_orders');
    $customers = $listing->customers($orders, [
        'min_spend' => null,
        'sort_by' => 'orders_count',
        'sort_dir' => 'desc',
    ])->get();

    // Establish that SQL actually grouped all three variants before testing enrichment.
    expect($customers)->toHaveCount(2)
        ->and((int) $customers[0]->orders_count)->toBe(3)
        ->and((float) $customers[0]->total_spent)->toBe(60.0);

    $enriched = $listing->enrich($customers, $orders, $authorizedWebsites);

    expect($enriched)->toHaveCount(2)
        ->and($enriched[0]['email'])->toBe($customers[0]->customer_email)
        ->and($enriched[0]['orders_count'])->toBe(3)
        ->and($enriched[0]['total_spent'])->toBe(60.0)
        ->and($enriched[0]['average_order_value'])->toBe(20.0)
        ->and($enriched[0]['websites'])->toBe(['First website', 'Second website', 'Third website'])
        ->and($enriched[0]['country'])->toBe('US')
        ->and($enriched[1]['email'])->toBe('separate@example.com')
        ->and($enriched[1]['orders_count'])->toBe(1)
        ->and($enriched[1]['total_spent'])->toBe(90.0)
        ->and($enriched[1]['websites'])->toBe(['First website'])
        ->and($enriched[1]['country'])->toBe('GB');

    $searchedOrders = $listing->orders($authorizedWebsites->pluck('id')->all(), ['search' => 'Needle'])->fromSub($source, 'wc_orders');
    $searched = $listing->customers($searchedOrders, ['min_spend' => null, 'sort_by' => 'orders_count', 'sort_dir' => 'desc'])->get();
    expect($searched)->toHaveCount(1)->and((int) $searched[0]->orders_count)->toBe(3);
    $searchedDetails = $listing->enrich($searched, $searchedOrders, $authorizedWebsites)->first();
    expect($searchedDetails['websites'])->toBe(['First website', 'Second website', 'Third website'])
        ->and($searchedDetails['total_spent'])->toBe(60.0);

    Http::assertNothingSent();
});
