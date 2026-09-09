<?php

use App\Models\UsefulAlert;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Str;

function usefulAlertInboxWebsite(User $owner): Website
{
    return Website::create(['user_id' => $owner->id, 'name' => 'Alert fixture',
        'slug' => (string) Str::uuid(), 'base_url' => 'https://alerts.example.test']);
}

function usefulAlertInboxFixture(User $actor, Website $website): array
{
    $alert = UsefulAlert::create([
        'user_id' => $actor->id, 'website_id' => $website->id, 'kind' => 'webhook_failed',
        'episode_key' => (string) Str::uuid(), 'count' => 2,
        'first_detected_at' => now(), 'last_detected_at' => now(),
    ]);
    $notification = $actor->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\UsefulAlertNotification',
        'data' => ['type' => 'useful_alert', 'alert_id' => $alert->id, 'message' => 'Webhooks need review',
            'redirect_url' => 'https://example.test/untrusted'],
    ]);

    return [$alert, $notification];
}

test('useful alert notifications open the currently authorized website and include resolved episodes', function (bool $admin) {
    $actor = User::factory()->create(['is_admin' => $admin]);
    $owner = $admin ? User::factory()->create() : $actor;
    $website = usefulAlertInboxWebsite($owner);
    [$alert, $notification] = usefulAlertInboxFixture($actor, $website);
    $alert->update(['resolved_at' => now()]);

    $this->actingAs($actor)->postJson(route('notifications.mark-as-read', $notification->id))
        ->assertOk()->assertJsonPath('redirect_url', route('alerts.index', [
            'website_id' => $website->id, 'kind' => 'webhook_failed', 'status' => 'all',
        ]))->assertJsonPath('unread_count', 0);
})->with([false, true]);

test('a historical useful alert loses its website redirect after ownership transfer or admin demotion', function (bool $admin) {
    $actor = User::factory()->create(['is_admin' => $admin]);
    $other = User::factory()->create();
    $website = usefulAlertInboxWebsite($admin ? $other : $actor);
    [, $notification] = usefulAlertInboxFixture($actor, $website);
    if ($admin) {
        $actor->update(['is_admin' => false]);
    } else {
        $website->update(['user_id' => $other->id]);
    }

    $this->actingAs($actor)->postJson(route('notifications.mark-as-read', $notification->id))
        ->assertOk()->assertJsonPath('redirect_url', route('alerts.index'));
})->with([false, true]);

test('useful alert redirects never follow an alert owned by another recipient', function () {
    $actor = User::factory()->create(['is_admin' => true]);
    $other = User::factory()->create();
    $website = usefulAlertInboxWebsite($other);
    [$alert] = usefulAlertInboxFixture($other, $website);
    $notification = $actor->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\UsefulAlertNotification',
        'data' => ['type' => 'useful_alert', 'alert_id' => $alert->id, 'message' => 'Fixture'],
    ]);

    $this->actingAs($actor)->postJson(route('notifications.mark-as-read', $notification->id))
        ->assertOk()->assertJsonPath('redirect_url', route('alerts.index'));
});

test('useful alert redirects reject malformed identifiers and ignore supplied destinations', function (mixed $identifier) {
    $actor = User::factory()->create();
    $notification = $actor->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\UsefulAlertNotification',
        'data' => ['type' => 'useful_alert', 'alert_id' => $identifier, 'message' => 'Fixture',
            'redirect_url' => 'https://example.test/untrusted'],
    ]);

    $this->actingAs($actor)->postJson(route('notifications.mark-as-read', $notification->id))
        ->assertOk()->assertJsonPath('redirect_url', route('alerts.index'));
})->with([null, -1, '1/../../settings', [1]]);
