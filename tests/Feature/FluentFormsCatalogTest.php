<?php

use App\Exceptions\FluentSyncException;
use App\Models\FfForm;
use App\Models\User;
use App\Models\Website;
use App\Services\FluentFormsCatalog;
use App\Services\FluentFormSchemaService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->website = Website::create([
        'user_id' => $this->owner->id, 'name' => 'Catalog demo', 'slug' => 'catalog-demo',
        'base_url' => 'https://catalog.example', 'status' => 'active',
        'ff_username' => 'synthetic-user', 'ff_app_password' => 'synthetic-password',
    ]);
});

test('form picker returns only real Laravel paginator records and never numeric metadata', function (int $total) {
    $records = array_map(fn ($id) => ['id' => (string) $id, 'title' => "Demo $id", 'private_field' => 'not-exposed'], range($total, 1));
    Http::fake(['*' => Http::response([
        'current_page' => 1, 'per_page' => 100, 'from' => 1, 'to' => $total,
        'last_page' => 1, 'total' => $total, 'data' => $records,
    ])]);
    $response = $this->actingAs($this->owner)->getJson(route('websites.forms', $this->website))
        ->assertOk()->assertJsonCount($total, 'forms')->assertHeader('Cache-Control', 'no-store, private');
    expect($response->json('forms'))->toBe(array_map(fn ($id) => ['id' => $id, 'title' => "Demo $id"], range($total, 1)));
    Http::assertSent(fn ($request) => $request['page'] === 1 && $request['per_page'] === 100);
    Http::assertSentCount(1);
})->with([8, 6, 5]);

test('catalog follows all pages and deduplicates real IDs across the complete list', function () {
    Http::fake(function ($request) {
        $page = (int) $request['page'];
        $ids = $page === 1 ? range(1, 10) : [10, 11, 12];

        return Http::response(['forms' => [
            'current_page' => $page, 'last_page' => 2, 'per_page' => 10,
            'data' => array_map(fn ($id) => ['id' => (string) $id, 'title' => "Form $id"], $ids),
        ]]);
    });
    $this->actingAs($this->owner)->getJson(route('websites.forms', $this->website))
        ->assertOk()->assertJsonCount(12, 'forms')->assertJsonPath('forms.11.id', 12);
    Http::assertSentCount(2);
});

test('catalog supports nested wrappers and explicit ID lists with safe title fallbacks', function () {
    Http::fake(['*' => Http::response(['data' => ['forms' => [3, '4', ['id' => 5, 'title' => ''], ['id' => 6, 'form_title' => 'Contact']]]])]);
    expect(app(FluentFormsCatalog::class)->fetch($this->website))->toBe([
        ['id' => 3, 'title' => 'Form #3'], ['id' => 4, 'title' => 'Form #4'],
        ['id' => 5, 'title' => 'Form #5'], ['id' => 6, 'title' => 'Contact'],
    ]);
});

test('valid empty catalogs stay empty', function ($payload) {
    Http::fake(['*' => Http::response($payload)]);
    $this->actingAs($this->owner)->getJson(route('websites.forms', $this->website))->assertOk()->assertExactJson(['forms' => []]);
})->with(['plain' => [[]], 'paginator' => [['current_page' => 1, 'last_page' => 1, 'total' => 0, 'data' => []]]]);

test('malformed catalogs return an error rather than invented forms or a false empty list', function ($payload) {
    Http::fake(['*' => Http::response($payload)]);
    $this->actingAs($this->owner)->getJson(route('websites.forms', $this->website))
        ->assertStatus(502)->assertJsonStructure(['error'])->assertJsonMissingPath('forms');
})->with([
    'invalid JSON' => '<html>not a catalog</html>',
    'empty object' => '{}',
    'metadata without records' => [['current_page' => 1, 'per_page' => 10, 'total' => 8]],
    'record without ID' => [[['title' => 'Invalid']]],
    'boolean ID' => [[true]],
    'invalid ID' => [[['id' => '1e2']]],
    'empty intermediate page' => [['data' => [], 'last_page' => 2]],
]);

test('a failed later catalog page is not returned as a successful partial picker', function () {
    Http::fakeSequence()->push(['data' => [['id' => 1]], 'last_page' => 2])->push(['message' => 'private-upstream-data'], 500);
    $response = $this->actingAs($this->owner)->getJson(route('websites.forms', $this->website))
        ->assertStatus(502)->assertJsonMissingPath('forms');
    expect($response->getContent())->not->toContain('private-upstream-data');
    Http::assertSentCount(2);
});

test('catalog repetition and incorrect current pages fail instead of looping', function ($payload) {
    Http::fake(['*' => Http::response($payload)]);
    expect(fn () => app(FluentFormsCatalog::class)->fetch($this->website))->toThrow(FluentSyncException::class);
    Http::assertSentCount(2);
})->with([
    'repeated records' => [['data' => [['id' => 1]], 'next_page_url' => 'https://untrusted.example/?page=2']],
    'incorrect page metadata' => [['data' => [['id' => 1]], 'current_page' => 1, 'last_page' => 2]],
]);

test('catalog page limit bounds an upstream that never finishes', function () {
    Http::fake(fn ($request) => Http::response(['data' => [['id' => $request['page']]], 'next_page_url' => 'https://untrusted.example/next']));
    expect(fn () => app(FluentFormsCatalog::class)->fetch($this->website))->toThrow(FluentSyncException::class, 'page limit');
    Http::assertSentCount(20);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://catalog.example/wp-json/fluentform/v1/forms?'));
});

test('form catalog access is limited to its owner and administrators', function () {
    $this->actingAs(User::factory()->create())->getJson(route('websites.forms', $this->website))->assertForbidden();
    Http::assertNothingSent();
    Http::fake(['*' => Http::response([['id' => 4]])]);
    $this->actingAs(User::factory()->create(['is_admin' => true]))->getJson(route('websites.forms', $this->website))->assertOk()->assertJsonPath('forms.0.id', 4);
});

test('bulk schema sync uses the complete paginated catalog without metadata IDs', function () {
    config(['queue.default' => 'sync']);
    Queue::fake();
    Http::fakeSequence()
        ->push(['current_page' => 1, 'last_page' => 2, 'per_page' => 1, 'total' => 2, 'data' => [['id' => '4']]])
        ->push(['current_page' => 2, 'last_page' => 2, 'per_page' => 1, 'total' => 2, 'data' => [['id' => '8']]]);
    $synced = [];
    $this->mock(FluentFormSchemaService::class)->shouldReceive('syncFormSchema')->twice()->andReturnUsing(function ($website, $formId) use (&$synced) {
        $synced[] = $formId;

        return new FfForm(['title' => 'Demo']);
    });
    $this->actingAs($this->owner)->post(route('submissions.sync-all-schemas', $this->website))
        ->assertSessionHas('success', fn ($message) => str_contains($message, '2 form schema(s). 0 failed.'));
    expect($synced)->toBe([4, 8]);
    Queue::assertNothingPushed();
});

test('bulk schema sync does not start imports when a later catalog page fails', function () {
    Http::fakeSequence()->push(['data' => [['id' => 4]], 'last_page' => 2])->push([], 500);
    $this->mock(FluentFormSchemaService::class)->shouldNotReceive('syncFormSchema');
    $this->actingAs($this->owner)->post(route('submissions.sync-all-schemas', $this->website))
        ->assertSessionHas('error')->assertSessionMissing('success');
});
