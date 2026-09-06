<?php

use App\Jobs\ProcessFluentWebhookEvent;
use App\Models\FfSubmission;
use App\Models\WebhookEvent;
use App\Models\Website;
use App\Services\FluentFormSchemaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('processes fluent webhook event with array payload and extracts payment data', function () {
    // Mock the schema service to avoid actual HTTP calls
    $mock = \Mockery::mock(FluentFormSchemaService::class);
    $mock->shouldReceive('syncFormSchema')->once()->andReturn(null);
    app()->instance(FluentFormSchemaService::class, $mock);

    $website = Website::create([
        'user_id' => \App\Models\User::factory()->create()->id,
        'name' => 'Test Website',
        'slug' => 'test-website-'.uniqid(),
        'base_url' => 'https://test.example.com',
        'status' => 'active',
        'timezone' => 'UTC',
        'webhook_secret' => 'test-secret',
    ]);

    // Create a webhook event with payload as an array containing a single object
    // This matches the format where payload begins with [{...}]
    $payload = [
        [
            '__submission' => [
                'id' => 12345,
                'form_id' => 1,
                'email' => 'test@example.com',
                'created_at' => '2024-01-15 10:30:00',
                'payment_status' => 'paid',
                'payment_total' => 2500, // in cents = $25.00
            ],
            '__order_items' => [
                [
                    'formatted_item_price' => '$25.00',
                    'formatted_line_total' => '$25.00',
                ],
            ],
            'first_name' => 'John',
            'last_name' => 'Doe',
            'email' => 'test@example.com',
        ],
    ];

    $event = WebhookEvent::create([
        'website_id' => $website->id,
        'source' => 'fluentforms',
        'topic' => 'form.submitted',
        'external_id' => '12345',
        'signature_valid' => true,
        'payload' => $payload,
        'received_at' => now(),
        'status' => 'queued',
    ]);

    // Process the event
    $job = new ProcessFluentWebhookEvent($event->id);
    $job->handle();

    // Refresh the event to get updated status
    $event->refresh();

    // Assert event was processed successfully
    expect($event->status)->toBe('processed');
    expect($event->processed_at)->not->toBeNull();

    // Assert submission was created with payment data
    $submission = FfSubmission::where('website_id', $website->id)
        ->where('form_id', 1)
        ->where('entry_id', 12345)
        ->first();

    expect($submission)->not->toBeNull();
    expect($submission->payment_status)->toBe('paid');
    expect((float) $submission->amount)->toBe(25.00);
    expect($submission->email)->toBe('test@example.com');
});

test('extracts payment amount from order items formatted_line_total when payment_total not available', function () {
    $mock = \Mockery::mock(FluentFormSchemaService::class);
    $mock->shouldReceive('syncFormSchema')->once()->andReturn(null);
    app()->instance(FluentFormSchemaService::class, $mock);

    $website = Website::create([
        'user_id' => \App\Models\User::factory()->create()->id,
        'name' => 'Test Website 2',
        'slug' => 'test-website-2-'.uniqid(),
        'base_url' => 'https://test2.example.com',
        'status' => 'active',
        'timezone' => 'UTC',
        'webhook_secret' => 'test-secret-2',
    ]);

    // Payload with payment data in order_items but no payment_total
    $payload = [
        [
            '__submission' => [
                'id' => 12346,
                'form_id' => 1,
                'email' => 'test2@example.com',
                'created_at' => '2024-01-15 10:35:00',
                'payment_status' => 'pending',
            ],
            '__order_items' => [
                [
                    'formatted_item_price' => '$50.00',
                    'formatted_line_total' => '$50.00',
                ],
            ],
            'email' => 'test2@example.com',
        ],
    ];

    $event = WebhookEvent::create([
        'website_id' => $website->id,
        'source' => 'fluentforms',
        'topic' => 'form.submitted',
        'external_id' => '12346',
        'signature_valid' => true,
        'payload' => $payload,
        'received_at' => now(),
        'status' => 'queued',
    ]);

    $job = new ProcessFluentWebhookEvent($event->id);
    $job->handle();

    $submission = FfSubmission::where('website_id', $website->id)
        ->where('form_id', 1)
        ->where('entry_id', 12346)
        ->first();

    expect($submission)->not->toBeNull();
    expect($submission->payment_status)->toBe('pending');
    expect((float) $submission->amount)->toBe(50.00);
});

test('handles payload that is already an object (not wrapped in array)', function () {
    $mock = \Mockery::mock(FluentFormSchemaService::class);
    $mock->shouldReceive('syncFormSchema')->once()->andReturn(null);
    app()->instance(FluentFormSchemaService::class, $mock);

    $website = Website::create([
        'user_id' => \App\Models\User::factory()->create()->id,
        'name' => 'Test Website 3',
        'slug' => 'test-website-3-'.uniqid(),
        'base_url' => 'https://test3.example.com',
        'status' => 'active',
        'timezone' => 'UTC',
        'webhook_secret' => 'test-secret-3',
    ]);

    // Payload as object (not wrapped in array) - backwards compatibility
    $payload = [
        '__submission' => [
            'id' => 12347,
            'form_id' => 1,
            'email' => 'test3@example.com',
            'created_at' => '2024-01-15 10:40:00',
            'payment_status' => 'failed',
            'payment_total' => 7500, // $75.00 in cents
        ],
        'email' => 'test3@example.com',
    ];

    $event = WebhookEvent::create([
        'website_id' => $website->id,
        'source' => 'fluentforms',
        'topic' => 'form.submitted',
        'external_id' => '12347',
        'signature_valid' => true,
        'payload' => $payload,
        'received_at' => now(),
        'status' => 'queued',
    ]);

    $job = new ProcessFluentWebhookEvent($event->id);
    $job->handle();

    $submission = FfSubmission::where('website_id', $website->id)
        ->where('form_id', 1)
        ->where('entry_id', 12347)
        ->first();

    expect($submission)->not->toBeNull();
    expect($submission->payment_status)->toBe('failed');
    expect((float) $submission->amount)->toBe(75.00);
});

test('handles missing payment data gracefully', function () {
    $mock = \Mockery::mock(FluentFormSchemaService::class);
    $mock->shouldReceive('syncFormSchema')->once()->andReturn(null);
    app()->instance(FluentFormSchemaService::class, $mock);

    $website = Website::create([
        'user_id' => \App\Models\User::factory()->create()->id,
        'name' => 'Test Website 4',
        'slug' => 'test-website-4-'.uniqid(),
        'base_url' => 'https://test4.example.com',
        'status' => 'active',
        'timezone' => 'UTC',
        'webhook_secret' => 'test-secret-4',
    ]);

    // Payload without payment data
    $payload = [
        [
            '__submission' => [
                'id' => 12348,
                'form_id' => 1,
                'email' => 'test4@example.com',
                'created_at' => '2024-01-15 10:45:00',
            ],
            'email' => 'test4@example.com',
        ],
    ];

    $event = WebhookEvent::create([
        'website_id' => $website->id,
        'source' => 'fluentforms',
        'topic' => 'form.submitted',
        'external_id' => '12348',
        'signature_valid' => true,
        'payload' => $payload,
        'received_at' => now(),
        'status' => 'queued',
    ]);

    $job = new ProcessFluentWebhookEvent($event->id);
    $job->handle();

    $submission = FfSubmission::where('website_id', $website->id)
        ->where('form_id', 1)
        ->where('entry_id', 12348)
        ->first();

    expect($submission)->not->toBeNull();
    expect($submission->payment_status)->toBeNull();
    expect($submission->amount)->toBeNull();
});
