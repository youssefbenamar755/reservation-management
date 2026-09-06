<?php

use App\Models\User;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function notificationAccessFixture(User $user, array $data = [], bool $read = false): DatabaseNotification
{
    return $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\FixtureNotification',
        'data' => $data + ['type' => 'order', 'order_id' => 321, 'message' => 'Fixture order received'],
        'read_at' => $read ? now()->subDay() : null,
    ]);
}

test('notification list is private and scoped to the user including administrator accounts', function (bool $admin) {
    $user = User::factory()->create(['is_admin' => $admin]);
    $other = User::factory()->create();
    $own = notificationAccessFixture($user);
    $foreign = notificationAccessFixture($other, ['message' => 'Other account fixture']);

    $response = $this->actingAs($user)->getJson(route('notifications.index'))
        ->assertOk()
        ->assertJsonCount(1, 'notifications')
        ->assertJsonPath('notifications.0.id', $own->id)
        ->assertJsonPath('unread_count', 1)
        ->assertJsonMissing(['id' => $foreign->id]);
    expect($response->headers->hasCacheControlDirective('private'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('no-store'))->toBeTrue()
        ->and($response->headers->hasCacheControlDirective('public'))->toBeFalse();
})->with([false, true]);

test('notification counts cover unread rows outside the displayed fifty', function () {
    $user = User::factory()->create();
    for ($i = 0; $i < 51; $i++) {
        notificationAccessFixture($user);
    }

    $this->actingAs($user)->getJson(route('notifications.index'))
        ->assertOk()->assertJsonCount(50, 'notifications')->assertJsonPath('unread_count', 51);
});

test('marking own notification read preserves redirects and returns the remaining unread count', function (array $data, ?string $routeName, ?int $entityId) {
    $user = User::factory()->create();
    $target = notificationAccessFixture($user, $data);
    notificationAccessFixture($user);
    notificationAccessFixture(User::factory()->create());
    $expectedRedirect = $routeName ? route($routeName, $entityId) : null;

    $this->actingAs($user)->postJson(route('notifications.mark-as-read', $target->id))
        ->assertOk()->assertExactJson(['success' => true, 'redirect_url' => $expectedRedirect, 'unread_count' => 1]);
    $readAt = $target->fresh()->read_at;
    expect($readAt)->not->toBeNull();

    $this->travel(1)->minutes();
    $this->postJson(route('notifications.mark-as-read', $target->id))
        ->assertOk()->assertJsonPath('unread_count', 1)->assertJsonPath('redirect_url', $expectedRedirect);
    expect($target->fresh()->read_at->equalTo($readAt))->toBeTrue();
})->with([
    'order' => [['type' => 'order', 'order_id' => 321], 'orders.show', 321],
    'submission' => [['type' => 'form_submission', 'submission_id' => 654], 'submissions.entry-details', 654],
    'unknown' => [['type' => 'unknown'], null, null],
    'missing identifier' => [['type' => 'order', 'order_id' => null], null, null],
]);

test('users and administrators cannot mark another recipients notification as read', function (bool $admin) {
    $user = User::factory()->create(['is_admin' => $admin]);
    $foreign = notificationAccessFixture(User::factory()->create());

    $this->actingAs($user)->postJson(route('notifications.mark-as-read', $foreign->id))->assertNotFound();
    expect($foreign->fresh()->read_at)->toBeNull();
})->with([false, true]);

test('read all updates unread rows in one query and preserves other recipients and existing read times', function (bool $admin) {
    $user = User::factory()->create(['is_admin' => $admin]);
    $foreign = notificationAccessFixture(User::factory()->create());
    $alreadyRead = notificationAccessFixture($user, read: true);
    $readAt = $alreadyRead->read_at;
    for ($i = 0; $i < 4; $i++) {
        notificationAccessFixture($user);
    }
    DB::enableQueryLog();

    $this->actingAs($user)->postJson(route('notifications.mark-all-as-read'))
        ->assertOk()->assertExactJson(['success' => true, 'unread_count' => 0]);

    $updates = collect(DB::getQueryLog())->filter(fn ($query) => preg_match('/^update ["`]?notifications["`]? /i', $query['query']));
    DB::disableQueryLog();
    expect($updates)->toHaveCount(1)
        ->and($user->unreadNotifications()->count())->toBe(0)
        ->and($foreign->fresh()->read_at)->toBeNull()
        ->and($alreadyRead->fresh()->read_at->equalTo($readAt))->toBeTrue();
    $this->postJson(route('notifications.mark-all-as-read'))->assertOk()->assertJsonPath('unread_count', 0);
})->with([false, true]);

test('notification endpoints require authentication', function () {
    $notification = notificationAccessFixture(User::factory()->create());
    $this->getJson(route('notifications.index'))->assertUnauthorized();
    $this->postJson(route('notifications.mark-as-read', $notification->id))->assertUnauthorized();
    $this->postJson(route('notifications.mark-all-as-read'))->assertUnauthorized();
    expect($notification->fresh()->read_at)->toBeNull();
});

test('read all reports a newly arrived notification instead of assuming the count is zero', function () {
    $user = User::factory()->create();
    notificationAccessFixture($user);
    $arrived = false;
    DB::listen(function ($query) use ($user, &$arrived) {
        if (! $arrived && preg_match('/^update ["`]?notifications["`]? /i', $query->sql)) {
            $arrived = true;
            // Simulate delivery between the bulk update and the response count query.
            notificationAccessFixture($user, ['message' => 'Newly arrived fixture']);
        }
    });

    $this->actingAs($user)->postJson(route('notifications.mark-all-as-read'))
        ->assertOk()->assertJsonPath('unread_count', 1);
    expect($arrived)->toBeTrue()->and($user->unreadNotifications()->count())->toBe(1);
});
