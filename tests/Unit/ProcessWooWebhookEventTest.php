<?php

use App\Jobs\ProcessWooWebhookEvent;
use App\Models\WcOrder;
use App\Models\WebhookEvent;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('processes order.updated webhook and updates order status', function () {
    $website = Website::create([
        'user_id' => \App\Models\User::factory()->create()->id,
        'name' => 'Test WooCommerce Site',
        'slug' => 'test-woo-site-'.uniqid(),
        'base_url' => 'https://test-woo.example.com',
        'status' => 'active',
        'timezone' => 'UTC',
        'webhook_secret' => 'test-secret',
    ]);

    // Create initial order with pending status
    $initialOrder = WcOrder::create([
        'website_id' => $website->id,
        'wp_order_id' => 12345,
        'status' => 'pending',
        'payment_status' => null,
        'currency' => 'USD',
        'total' => 100.00,
        'customer_email' => 'customer@example.com',
        'customer_name' => 'John Doe',
        'payload' => [
            'id' => 12345,
            'status' => 'pending',
            'currency' => 'USD',
            'total' => '100.00',
        ],
    ]);

    // Create order.updated webhook event with new status
    $payload = [
        'id' => 12345,
        'status' => 'processing',
        'currency' => 'USD',
        'total' => '100.00',
        'date_paid' => '2024-01-15T10:30:00',
        'date_created_gmt' => '2024-01-15T09:00:00',
        'date_modified_gmt' => '2024-01-15T10:30:00',
        'billing' => [
            'email' => 'customer@example.com',
            'first_name' => 'John',
            'last_name' => 'Doe',
        ],
    ];

    $event = WebhookEvent::create([
        'website_id' => $website->id,
        'source' => 'woocommerce',
        'topic' => 'order.updated',
        'external_id' => '12345',
        'signature_valid' => true,
        'payload' => $payload,
        'received_at' => now(),
        'status' => 'queued',
    ]);

    // Process the event
    $job = new ProcessWooWebhookEvent($event->id);
    app()->call([$job, 'handle']);

    // Refresh the event to get updated status
    $event->refresh();

    // Assert event was processed successfully
    expect($event->status)->toBe('processed');
    expect($event->processed_at)->not->toBeNull();

    // Assert order was updated (not duplicated)
    $orders = WcOrder::where('website_id', $website->id)
        ->where('wp_order_id', 12345)
        ->get();

    expect($orders)->toHaveCount(1); // No duplicate created

    $updatedOrder = $orders->first();
    expect($updatedOrder->status)->toBe('processing'); // Status updated
    expect($updatedOrder->payment_status)->toBe('paid'); // Payment status derived from date_paid
    expect($updatedOrder->total)->toBe('100.00');
    expect($updatedOrder->currency)->toBe('USD');
    expect($updatedOrder->updated_at_wp)->not->toBeNull();
});

test('processes order.updated webhook and updates payment status to null when unpaid', function () {
    $website = Website::create([
        'user_id' => \App\Models\User::factory()->create()->id,
        'name' => 'Test WooCommerce Site 2',
        'slug' => 'test-woo-site-2-'.uniqid(),
        'base_url' => 'https://test-woo2.example.com',
        'status' => 'active',
        'timezone' => 'UTC',
        'webhook_secret' => 'test-secret-2',
    ]);

    // Create initial order with paid status
    $initialOrder = WcOrder::create([
        'website_id' => $website->id,
        'wp_order_id' => 12346,
        'status' => 'processing',
        'payment_status' => 'paid',
        'currency' => 'USD',
        'total' => 200.00,
        'customer_email' => 'customer2@example.com',
        'customer_name' => 'Jane Doe',
        'payload' => [],
    ]);

    // Create order.updated webhook event with unpaid status (date_paid is null)
    $payload = [
        'id' => 12346,
        'status' => 'cancelled',
        'currency' => 'USD',
        'total' => '200.00',
        'date_paid' => null, // Unpaid
        'date_created_gmt' => '2024-01-15T09:00:00',
        'date_modified_gmt' => '2024-01-15T11:00:00',
        'billing' => [
            'email' => 'customer2@example.com',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ],
    ];

    $event = WebhookEvent::create([
        'website_id' => $website->id,
        'source' => 'woocommerce',
        'topic' => 'order.updated',
        'external_id' => '12346',
        'signature_valid' => true,
        'payload' => $payload,
        'received_at' => now(),
        'status' => 'queued',
    ]);

    // Process the event
    $job = new ProcessWooWebhookEvent($event->id);
    app()->call([$job, 'handle']);

    // Assert order was updated
    $order = WcOrder::where('website_id', $website->id)
        ->where('wp_order_id', 12346)
        ->first();

    expect($order)->not->toBeNull();
    expect($order->status)->toBe('cancelled'); // Status updated
    expect($order->payment_status)->toBeNull(); // Payment status is null when unpaid
});

test('processes order.created webhook and creates new order', function () {
    $website = Website::create([
        'user_id' => \App\Models\User::factory()->create()->id,
        'name' => 'Test WooCommerce Site 3',
        'slug' => 'test-woo-site-3-'.uniqid(),
        'base_url' => 'https://test-woo3.example.com',
        'status' => 'active',
        'timezone' => 'UTC',
        'webhook_secret' => 'test-secret-3',
    ]);

    // Create order.created webhook event
    $payload = [
        'id' => 12347,
        'status' => 'pending',
        'currency' => 'EUR',
        'total' => '150.00',
        'date_paid' => null,
        'date_created_gmt' => '2024-01-15T12:00:00',
        'date_modified_gmt' => '2024-01-15T12:00:00',
        'billing' => [
            'email' => 'newcustomer@example.com',
            'first_name' => 'New',
            'last_name' => 'Customer',
        ],
    ];

    $event = WebhookEvent::create([
        'website_id' => $website->id,
        'source' => 'woocommerce',
        'topic' => 'order.created',
        'external_id' => '12347',
        'signature_valid' => true,
        'payload' => $payload,
        'received_at' => now(),
        'status' => 'queued',
    ]);

    // Process the event
    $job = new ProcessWooWebhookEvent($event->id);
    app()->call([$job, 'handle']);

    // Assert order was created
    $order = WcOrder::where('website_id', $website->id)
        ->where('wp_order_id', 12347)
        ->first();

    expect($order)->not->toBeNull();
    expect($order->status)->toBe('pending');
    expect($order->currency)->toBe('EUR');
    expect($order->total)->toBe('150.00');
    expect($order->customer_email)->toBe('newcustomer@example.com');
});

test('handles idempotency - receiving same webhook multiple times does not create duplicates', function () {
    $website = Website::create([
        'user_id' => \App\Models\User::factory()->create()->id,
        'name' => 'Test WooCommerce Site 4',
        'slug' => 'test-woo-site-4-'.uniqid(),
        'base_url' => 'https://test-woo4.example.com',
        'status' => 'active',
        'timezone' => 'UTC',
        'webhook_secret' => 'test-secret-4',
    ]);

    $payload = [
        'id' => 12348,
        'status' => 'completed',
        'currency' => 'GBP',
        'total' => '250.00',
        'date_paid' => '2024-01-15T13:00:00',
        'date_created_gmt' => '2024-01-15T12:00:00',
        'date_modified_gmt' => '2024-01-15T13:00:00',
        'billing' => [
            'email' => 'idempotent@example.com',
            'first_name' => 'Idempotent',
            'last_name' => 'Test',
        ],
    ];

    // Process the same webhook event multiple times
    for ($i = 1; $i <= 3; $i++) {
        $event = WebhookEvent::create([
            'website_id' => $website->id,
            'source' => 'woocommerce',
            'topic' => 'order.updated',
            'external_id' => '12348',
            'signature_valid' => true,
            'payload' => $payload,
            'received_at' => now(),
            'status' => 'queued',
        ]);

        $job = new ProcessWooWebhookEvent($event->id);
        app()->call([$job, 'handle']);
    }

    // Assert only one order exists (no duplicates)
    $orders = WcOrder::where('website_id', $website->id)
        ->where('wp_order_id', 12348)
        ->get();

    expect($orders)->toHaveCount(1);
    expect($orders->first()->status)->toBe('completed');
    expect($orders->first()->payment_status)->toBe('paid');
});

test('extracts payment_status from payment_status field when available', function () {
    $website = Website::create([
        'user_id' => \App\Models\User::factory()->create()->id,
        'name' => 'Test WooCommerce Site 5',
        'slug' => 'test-woo-site-5-'.uniqid(),
        'base_url' => 'https://test-woo5.example.com',
        'status' => 'active',
        'timezone' => 'UTC',
        'webhook_secret' => 'test-secret-5',
    ]);

    // Payload with direct payment_status field (some plugins add this)
    $payload = [
        'id' => 12349,
        'status' => 'processing',
        'payment_status' => 'partial', // Direct payment_status field
        'currency' => 'USD',
        'total' => '300.00',
        'date_paid' => null, // Even though date_paid is null, payment_status takes precedence
        'date_created_gmt' => '2024-01-15T14:00:00',
        'date_modified_gmt' => '2024-01-15T14:00:00',
        'billing' => [
            'email' => 'partial@example.com',
            'first_name' => 'Partial',
            'last_name' => 'Payment',
        ],
    ];

    $event = WebhookEvent::create([
        'website_id' => $website->id,
        'source' => 'woocommerce',
        'topic' => 'order.updated',
        'external_id' => '12349',
        'signature_valid' => true,
        'payload' => $payload,
        'received_at' => now(),
        'status' => 'queued',
    ]);

    $job = new ProcessWooWebhookEvent($event->id);
    app()->call([$job, 'handle']);

    $order = WcOrder::where('website_id', $website->id)
        ->where('wp_order_id', 12349)
        ->first();

    expect($order)->not->toBeNull();
    expect($order->payment_status)->toBe('partial'); // Uses payment_status field directly
});
