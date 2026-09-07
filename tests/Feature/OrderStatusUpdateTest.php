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

test('failed or unconfirmed status updates preserve the last confirmed order', function (string $failure) {
    $original = $this->order->refresh()->getAttributes();
    $payload = array_replace($this->payload, ['status' => 'completed', 'date_modified_gmt' => '2026-09-01T10:00:00']);
    Http::fake(function () use ($failure, $payload) {
        return match ($failure) {
            'rejected' => Http::response(['message' => 'Remote failure'], 500),
            'timeout' => throw new \Illuminate\Http\Client\ConnectionException('Timed out'),
            'invalid json' => Http::response('not JSON'),
            'empty object' => Http::response([]),
            'wrong order' => Http::response(array_replace($payload, ['id' => 99])),
            'missing status' => Http::response(array_diff_key($payload, ['status' => true])),
            'partial order' => Http::response(['id' => 42, 'status' => 'completed']),
            'invalid date' => Http::response(array_replace($payload, ['date_modified_gmt' => 'invalid'])),
            'missing customer' => Http::response(array_replace($payload, ['billing' => []])),
            'missing payment date' => Http::response(array_diff_key($payload, ['date_paid' => true])),
        };
    });
    $this->actingAs($this->user)->from('/orders/'.$this->order->id)
        ->put(route('orders.update', $this->order), ['status' => 'completed'])
        ->assertRedirect('/orders/'.$this->order->id)->assertSessionHas('error')->assertSessionMissing('success');
    expect($this->order->refresh()->getAttributes())->toBe($original);
    if ($failure !== 'timeout') {
        Http::assertSentCount(1);
    }
})->with(['rejected', 'timeout', 'invalid json', 'empty object', 'wrong order', 'missing status', 'partial order', 'invalid date', 'missing customer', 'missing payment date']);

test('missing WooCommerce credentials cannot create a local only status change', function () {
    $this->website->update(['wc_consumer_key' => null, 'wc_consumer_secret' => null]);
    $this->actingAs($this->user)->put(route('orders.update', $this->order), ['status' => 'completed'])
        ->assertRedirect()->assertSessionHas('error')->assertSessionMissing('success');
    expect($this->order->refresh()->status)->toBe('pending');
    Http::assertNothingSent();
});

test('a failed status request cannot undo a webhook received while waiting', function () {
    $newerPayload = array_replace($this->payload, ['status' => 'processing', 'date_modified_gmt' => '2026-09-01T11:00:00']);
    Http::fake(function () use ($newerPayload) {
        app(WooCommerceOrderStore::class)->store($this->website->id, $newerPayload);

        return Http::response([], 503);
    });
    $this->actingAs($this->user)->put(route('orders.update', $this->order), ['status' => 'completed'])
        ->assertSessionHas('error');
    expect($this->order->refresh()->status)->toBe('processing')->and($this->order->payload)->toBe($newerPayload);
});

test('a confirmed different remote status is stored without claiming the requested change succeeded', function () {
    $payload = array_replace($this->payload, ['status' => 'on-hold', 'date_modified_gmt' => '2026-09-01T11:00:00']);
    Http::fake(['*' => Http::response($payload)]);
    $this->actingAs($this->user)->put(route('orders.update', $this->order), ['status' => 'completed'])
        ->assertSessionHas('error')->assertSessionMissing('success');
    expect($this->order->refresh()->status)->toBe('on-hold')->and($this->order->payload)->toBe($payload);
});

test('a newer webhook does not turn an unconfirmed requested status into a success message', function () {
    $confirmed = array_replace($this->payload, ['status' => 'on-hold', 'date_modified_gmt' => '2026-09-01T10:00:00']);
    $newer = array_replace($this->payload, ['status' => 'processing', 'date_modified_gmt' => '2026-09-01T11:00:00']);
    Http::fake(function () use ($confirmed, $newer) {
        app(WooCommerceOrderStore::class)->store($this->website->id, $newer);

        return Http::response($confirmed);
    });
    $this->actingAs($this->user)->put(route('orders.update', $this->order), ['status' => 'completed'])
        ->assertSessionHas('error')->assertSessionMissing('success');
    expect($this->order->refresh()->status)->toBe('processing');
});

test('status confirmation preserves omitted optional order details while honoring explicit empty arrays', function (bool $clear) {
    $existing = array_replace($this->payload, ['meta_data' => [['key' => '_fluent_id', 'value' => 99]], 'line_items' => [['id' => 1, 'name' => 'Reservation']], 'billing' => [...$this->payload['billing'], 'country' => 'MA']]);
    app(WooCommerceOrderStore::class)->store($this->website->id, $existing);
    $confirmed = array_replace($this->payload, ['status' => 'processing', 'date_modified_gmt' => '2026-09-01T12:00:00']);
    if ($clear) {
        $confirmed = [...$confirmed, 'meta_data' => [], 'line_items' => []];
    }
    Http::fake(['*' => Http::response($confirmed)]);
    $this->actingAs($this->user)->put(route('orders.update', $this->order), ['status' => 'processing'])
        ->assertSessionHas('success')->assertSessionMissing('error');
    $payload = $this->order->refresh()->payload;
    expect($payload['meta_data'])->toBe($clear ? [] : $existing['meta_data'])
        ->and($payload['line_items'])->toBe($clear ? [] : $existing['line_items'])
        ->and($payload['billing']['country'])->toBe('MA')
        ->and($this->order->customer_email)->toBe('customer@example.com');
})->with([false, true]);
