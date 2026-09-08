<?php

use App\Models\FfSubmission;
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
    $this->website = Website::create(['user_id' => $this->owner->id, 'name' => 'Entry website', 'slug' => 'entries', 'base_url' => 'https://entries.example']);
    $this->entry = FfSubmission::create(['website_id' => $this->website->id, 'form_id' => 3, 'entry_id' => 99,
        'email' => 'same@example.test', 'payment_status' => 'paid', 'payload' => []]);
    $this->actingAs($this->owner);
});

function linkedSubmissionOrder(Website $website, array $metadata, int $wpId = 42): WcOrder
{
    return WcOrder::create(['website_id' => $website->id, 'wp_order_id' => $wpId, 'status' => 'processing', 'customer_email' => 'same@example.test',
        'payload' => ['meta_data' => $metadata, 'billing' => ['private' => 'not-in-linked-order'], 'line_items' => [['private' => 'not-in-linked-order']]]]);
}

test('entry details expose only the same website explicitly linked order and its update permission', function ($entryId, bool $admin) {
    $order = linkedSubmissionOrder($this->website, [['id' => 1001, 'key' => '_fluent_id', 'value' => $entryId]]);
    if ($admin) {
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }
    $this->get(route('submissions.entry-details', $this->entry))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Submissions/EntryDetails')->where('entry.payment_status', 'paid')
        ->where('linkedOrder', ['id' => $order->id, 'wp_order_id' => 42, 'status' => 'processing', 'website_name' => 'Entry website', 'can_update_status' => true]));
    Http::assertNothingSent();
})->with([[99, false], ['99', false], [99, true]]);

test('entry details never infer a linked order from email unrelated metadata or malformed metadata', function (array $metadata) {
    linkedSubmissionOrder($this->website, $metadata);
    $this->get(route('submissions.entry-details', $this->entry))->assertOk()->assertInertia(fn (Assert $page) => $page->where('linkedOrder', null));
    Http::assertNothingSent();
})->with([
    [[]], [[['key' => '_other_entry_id', 'value' => 99]]], [[['key' => '_fluent_id', 'value' => 999]]],
    [[['key' => '_fluent_id', 'value' => ['99']]]], [[['key' => '_fluent_id', 'value' => true]]],
    [['malformed', 12, null]], [[['key' => '_fluent_id', 'value' => 99], ['key' => '_fluent_id', 'value' => 100]]],
]);

test('entry order matching is isolated from other websites and foreign entry access remains forbidden', function () {
    $other = Website::create(['user_id' => User::factory()->create()->id, 'name' => 'Other website', 'slug' => 'other', 'base_url' => 'https://other.example']);
    linkedSubmissionOrder($other, [['key' => '_fluent_id', 'value' => 99]]);
    $foreign = FfSubmission::create(['website_id' => $other->id, 'form_id' => 3, 'entry_id' => 99, 'payload' => []]);
    $this->get(route('submissions.entry-details', $this->entry))->assertOk()->assertInertia(fn (Assert $page) => $page->where('linkedOrder', null));
    $this->get(route('submissions.entry-details', $foreign))->assertForbidden();
});

test('ambiguous entry IDs across forms or orders do not expose a status editor', function (string $ambiguity) {
    linkedSubmissionOrder($this->website, [['key' => '_fluent_id', 'value' => 99]]);
    if ($ambiguity === 'forms') {
        FfSubmission::create(['website_id' => $this->website->id, 'form_id' => 4, 'entry_id' => 99, 'payload' => []]);
    } else {
        linkedSubmissionOrder($this->website, [['key' => '_fluent_id', 'value' => '99']], 43);
    }
    $this->get(route('submissions.entry-details', $this->entry))->assertOk()->assertInertia(fn (Assert $page) => $page->where('linkedOrder', null));
})->with(['forms', 'orders']);

test('linked order permissions use the update policy separately from entry view permission', function () {
    linkedSubmissionOrder($this->website, [['key' => '_fluent_id', 'value' => 99]]);
    $this->partialMock(\App\Policies\WcOrderPolicy::class, function ($mock) {
        $mock->shouldReceive('update')->once()->andReturn(false);
    });
    $this->get(route('submissions.entry-details', $this->entry))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('linkedOrder.status', 'processing')->where('linkedOrder.can_update_status', false));
});

test('entry only partial reloads skip the linked order metadata lookup', function () {
    linkedSubmissionOrder($this->website, [['key' => '_fluent_id', 'value' => 99]]);
    $queries = [];
    \Illuminate\Support\Facades\DB::listen(function ($query) use (&$queries) {
        $queries[] = $query->sql;
    });
    $this->get(route('submissions.entry-details', $this->entry), [
        'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Submissions/EntryDetails', 'X-Inertia-Partial-Data' => 'entry',
        'X-Inertia-Version' => app(\App\Http\Middleware\HandleInertiaRequests::class)->version(\Illuminate\Http\Request::create('/submissions/entries/'.$this->entry->id)) ?? '',
    ])->assertOk()->assertJsonPath('props.entry.id', $this->entry->id)->assertJsonMissingPath('props.linkedOrder');
    expect(collect($queries)->filter(fn ($sql) => str_contains($sql, 'wc_orders')))->toBeEmpty();
});
