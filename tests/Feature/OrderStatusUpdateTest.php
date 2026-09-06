<?php

use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Services\WooCommerceOrderStore;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create();
    $this->website = Website::create([
        'user_id' => $this->user->id,
        'name' => 'Order website',
        'slug' => 'order-website',
        'base_url' => 'https://orders.example',
        'wc_consumer_key' => 'test-key',
        'wc_consumer_secret' => 'test-secret',
    ]);
    $this->payload = [
        'id' => 42,
        'status' => 'pending',
        'currency' => 'USD',
        'total' => '100.00',
        'date_created_gmt' => '2026-09-01T09:00:00',
        'date_modified_gmt' => '2026-09-01T09:00:00',
        'date_paid' => null,
        'billing' => ['email' => 'customer@example.com', 'first_name' => 'Test', 'last_name' => 'Customer'],
    ];
    $this->order = app(WooCommerceOrderStore::class)->store($this->website->id, $this->payload)['order'];
});

test('a delayed status update response cannot overwrite a newer webhook state', function () {
    $responsePayload = array_replace($this->payload, [
        'status' => 'processing',
        'date_modified_gmt' => '2026-09-01T10:00:00',
    ]);
    $newerPayload = array_replace($this->payload, [
        'status' => 'completed',
        'total' => '150.00',
        'date_modified_gmt' => '2026-09-01T10:01:00',
        'date_paid' => '2026-09-01T10:01:00',
    ]);

    Http::fake(function () use ($responsePayload, $newerPayload) {
        // Simulate a newer webhook being stored while this request awaits WooCommerce.
        app(WooCommerceOrderStore::class)->store($this->website->id, $newerPayload);

        return Http::response($responsePayload);
    });

    $this->actingAs($this->user)
        ->from('/orders/'.$this->order->id)
        ->put(route('orders.update', $this->order), ['status' => 'processing'])
        ->assertRedirect('/orders/'.$this->order->id)
        ->assertSessionHas('success', 'WooCommerce accepted the status update. A newer order update was retained locally.')
        ->assertSessionMissing('error');

    $this->order->refresh();
    expect($this->order->status)->toBe('completed')
        ->and($this->order->total)->toBe('150.00')
        ->and($this->order->payment_status)->toBe('paid')
        ->and($this->order->updated_at_wp->format('Y-m-d H:i:s'))->toBe('2026-09-01 10:01:00')
        ->and($this->order->payload)->toBe($newerPayload)
        ->and(WcOrder::count())->toBe(1);

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'PUT'
        && $request->url() === 'https://orders.example/wp-json/wc/v3/orders/42'
        && $request['status'] === 'processing');
});

test('a current WooCommerce status response is stored and reports success', function () {
    $responsePayload = array_replace($this->payload, [
        'status' => 'completed',
        'date_modified_gmt' => '2026-09-01T10:00:00',
        'date_paid' => '2026-09-01T10:00:00',
    ]);
    Http::fake(['https://orders.example/wp-json/wc/v3/orders/42' => Http::response($responsePayload)]);

    $this->actingAs($this->user)
        ->from('/orders/'.$this->order->id)
        ->put(route('orders.update', $this->order), ['status' => 'completed'])
        ->assertRedirect('/orders/'.$this->order->id)
        ->assertSessionHas('success', 'Order status updated successfully in WooCommerce and locally.')
        ->assertSessionMissing('error');

    $this->order->refresh();
    expect($this->order->status)->toBe('completed')
        ->and($this->order->payment_status)->toBe('paid')
        ->and($this->order->updated_at_wp->format('Y-m-d H:i:s'))->toBe('2026-09-01 10:00:00')
        ->and($this->order->payload)->toBe($responsePayload)
        ->and(WcOrder::count())->toBe(1);

    Http::assertSentCount(1);
});
