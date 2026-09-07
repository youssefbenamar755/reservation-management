<?php

use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->website = Website::create(['user_id' => $this->owner->id, 'name' => 'Notes website', 'slug' => 'notes-demo', 'base_url' => 'https://notes.example', 'wc_consumer_key' => 'test-key', 'wc_consumer_secret' => 'test-secret']);
    $this->order = WcOrder::create(['website_id' => $this->website->id, 'wp_order_id' => 42, 'status' => 'pending', 'total' => 10, 'currency' => 'USD', 'customer_email' => 'demo@example.test', 'payload' => ['meta_data' => []]]);
});

test('opening order details never waits for an external notes request', function () {
    $this->actingAs($this->owner)->get(route('orders.show', $this->order))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Orders/Show')->where('notesAvailable', true)->missing('orderNotes')->where('order.id', $this->order->id));
    Http::assertNothingSent();
});

test('notes load through a separate authorized private response', function () {
    Http::fake(['https://notes.example/wp-json/wc/v3/orders/42/notes' => Http::response([['id' => 7, 'note' => '<b>Order received</b>', 'author' => 'Staff', 'date_created' => '2026-09-07T09:00:00', 'customer_note' => false, 'unneeded' => 'excluded']])]);
    $response = $this->actingAs($this->owner)->getJson(route('orders.notes', $this->order))->assertOk()
        ->assertJsonPath('notes.0.id', 7)->assertJsonPath('notes.0.note', '<b>Order received</b>')->assertJsonMissingPath('notes.0.unneeded');
    expect($response->headers->get('Cache-Control'))->toContain('no-store')->toContain('private');
    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request->method() === 'GET' && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-key:test-secret')));
});

test('notes access is limited to the website owner and administrators', function () {
    $this->getJson(route('orders.notes', $this->order))->assertUnauthorized();
    $this->actingAs(User::factory()->create())->getJson(route('orders.notes', $this->order))->assertForbidden();
    Http::assertNothingSent();
    Http::fake(['*' => Http::response([])]);
    $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson(route('orders.notes', $this->order))->assertOk()->assertExactJson(['notes' => []]);
});

test('notes failure is explicit and a later retry can succeed', function () {
    Http::fakeSequence()->push(['message' => 'Private remote details'], 500)->push([['id' => 1, 'note' => 'Recovered']]);
    $this->actingAs($this->owner)->getJson(route('orders.notes', $this->order))->assertStatus(502)->assertJsonPath('message', 'Order notes could not be loaded. Please retry.');
    $this->getJson(route('orders.notes', $this->order))->assertOk()->assertJsonPath('notes.0.note', 'Recovered');
    Http::assertSentCount(2);
});

test('notes rejects invalid upstream responses and handles unavailable connections', function (string $failure) {
    if ($failure === 'no credentials') {
        $this->website->update(['wc_consumer_key' => null]);
    } else {
        Http::fake(fn () => match ($failure) {
            'timeout' => throw new \Illuminate\Http\Client\ConnectionException('Timeout'),
            'object' => Http::response(['code' => 'invalid']),
            'malformed notes' => Http::response([['id' => 1, 'note' => ['invalid']]]),
        });
    }
    $this->actingAs($this->owner)->getJson(route('orders.notes', $this->order))->assertStatus($failure === 'no credentials' ? 409 : 502);
    if ($failure === 'no credentials') {
        Http::assertNothingSent();
    }
})->with(['no credentials', 'timeout', 'object', 'malformed notes']);
