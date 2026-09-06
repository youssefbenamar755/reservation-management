<?php

use App\Events\NewWcOrderReceived;
use App\Jobs\ProcessWooWebhookEvent;
use App\Models\FfSubmission;
use App\Models\User;
use App\Models\WebhookEvent;
use App\Models\Website;
use App\Notifications\NewFormSubmissionNotification;
use App\Notifications\NewOrderNotification;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['broadcasting.default' => 'null']);
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->website = Website::create([
        'user_id' => $this->owner->id,
        'name' => 'Fixture store',
        'slug' => 'fixture-store',
        'base_url' => 'https://fixture-store.example.test',
    ]);
    $this->payload = [
        'id' => 42,
        'status' => 'pending',
        'currency' => 'EUR',
        'total' => '0.00',
        'date_created_gmt' => '2026-09-06T09:00:00',
        'date_modified_gmt' => '2026-09-06T09:00:00',
        'billing' => ['email' => 'customer@example.test'],
    ];
    $this->deliver = function (array $payload): WebhookEvent {
        $event = WebhookEvent::create([
            'website_id' => $this->website->id,
            'source' => 'woocommerce',
            'topic' => 'order.created',
            'external_id' => $payload['id'],
            'payload' => $payload,
            'signature_valid' => true,
            'status' => 'queued',
            'received_at' => now(),
        ]);
        app()->call([new ProcessWooWebhookEvent($event->id), 'handle']);

        return $event->fresh();
    };
});

test('new Woo orders deliver one saved and canonical live notification per owner and admin', function (bool $ownerIsAdmin) {
    Event::fake([NewWcOrderReceived::class, BroadcastNotificationCreated::class]);
    $this->owner->update(['is_admin' => $ownerIsAdmin]);
    $admin = User::factory()->create(['is_admin' => true]);
    $other = User::factory()->create();

    $first = ($this->deliver)($this->payload);
    app()->call([new ProcessWooWebhookEvent($first->id), 'handle']);
    ($this->deliver)($this->payload);
    ($this->deliver)(array_replace($this->payload, ['status' => 'completed', 'date_modified_gmt' => '2026-09-06T10:00:00']));
    ($this->deliver)($this->payload);

    expect($first->status)->toBe('processed')
        ->and($other->notifications()->count())->toBe(0);
    $this->assertDatabaseCount('notifications', 2);
    Event::assertDispatchedTimes(BroadcastNotificationCreated::class, 2);

    $events = Event::dispatched(BroadcastNotificationCreated::class)->map(fn ($arguments) => $arguments[0]);
    $this->assertEqualsCanonicalizing([$this->owner->id, $admin->id], $events->pluck('notifiable.id')->all());
    foreach ($events as $event) {
        $saved = $event->notifiable->notifications()->where('id', $event->notification->id)->sole();
        $payload = $event->broadcastWith();

        expect($saved->type)->toBe(NewOrderNotification::class)
            ->and($saved->read_at)->toBeNull()
            ->and($payload['id'])->toBe($saved->id)
            ->and($payload['type'])->toBe('order')
            ->and($payload['data'])->toBe($saved->data)
            ->and($payload['message'])->toBe('New order #42 from Fixture store — EUR 0.00')
            ->and($payload['data']['currency'])->toBe('EUR')
            ->and($payload['read_at'])->toBeNull()
            ->and($payload['redirect_url'])->toBe(route('orders.show', $saved->data['order_id']))
            ->and($event->broadcastAs())->toBe('notification')
            ->and(array_map(fn ($channel) => $channel->name, $event->broadcastOn()))
            ->toBe(['private-App.Models.User.'.$event->notifiable->id]);
    }
    Http::assertNothingSent();
})->with(['regular owner' => false, 'admin owner' => true]);

test('notification broadcast failures preserve saved inbox delivery without replay duplicates', function () {
    Event::fake([NewWcOrderReceived::class]);
    User::factory()->create(['is_admin' => true]);
    $attempts = 0;
    Event::listen(BroadcastNotificationCreated::class, function () use (&$attempts) {
        $attempts++;
        throw new RuntimeException('Fixture notification broadcaster outage');
    });

    $event = ($this->deliver)($this->payload);
    ($this->deliver)($this->payload);

    expect($attempts)->toBe(2)
        ->and($event->status)->toBe('processed');
    $this->assertDatabaseCount('notifications', 2);
    Http::assertNothingSent();
});

test('form notifications retain their canonical live type and saved inbox id', function () {
    Event::fake([BroadcastNotificationCreated::class]);
    $submission = FfSubmission::create([
        'website_id' => $this->website->id,
        'entry_id' => 42,
        'form_id' => 4,
        'payload' => ['response' => ['email' => 'customer@example.test']],
    ]);
    $this->owner->notifyNow(new NewFormSubmissionNotification($submission));

    $saved = $this->owner->notifications()->sole();
    Event::assertDispatched(BroadcastNotificationCreated::class, function ($event) use ($saved) {
        $payload = $event->broadcastWith();

        return $payload['type'] === 'form_submission'
            && $payload['id'] === $saved->id
            && $payload['data'] === $saved->data
            && $event->broadcastAs() === 'notification';
    });
    Http::assertNothingSent();
});
