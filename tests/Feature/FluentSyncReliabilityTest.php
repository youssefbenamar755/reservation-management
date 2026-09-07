<?php

use App\Exceptions\FluentSyncException;
use App\Jobs\SyncFluentSubmissions;
use App\Models\FfForm;
use App\Models\FfSubmission;
use App\Models\User;
use App\Models\Website;
use App\Services\FluentFormSchemaService;
use App\Services\FluentFormSubmissionService;
use App\Services\FluentFormSyncService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    Http::preventStrayRequests();
    config(['queue.default' => 'sync']);
    $this->owner = User::factory()->create();
    $this->website = Website::create([
        'user_id' => $this->owner->id, 'name' => 'Fluent demo', 'slug' => 'fluent-demo',
        'base_url' => 'https://fluent.example', 'status' => 'active',
        'ff_username' => 'synthetic-user', 'ff_app_password' => 'synthetic-password',
        'last_sync_at' => '2026-01-01 00:00:00',
    ]);
    $this->sync = app(FluentFormSyncService::class);
});

function fluentRecord(int $id, array $overrides = []): array
{
    return array_merge(['id' => $id, 'form_id' => 4, 'created_at' => '2026-09-01 12:00:00', 'response' => ['email' => 'synthetic@example.test']], $overrides);
}

test('Fluent HTTP and malformed response failures remain incomplete and do not masquerade as empty', function ($body, int $status) {
    Http::fake(['*' => Http::response($body, $status)]);
    expect(fn () => $this->sync->syncNextPage($this->website, 4))->toThrow(FluentSyncException::class);
    expect($this->website->fresh()->last_sync_at->format('Y-m-d'))->toBe('2026-01-01');
    expect($this->sync->progress($this->website, 4)['page'])->toBe(1);
    expect(FfSubmission::count())->toBe(0);
    Http::assertSentCount(1);
})->with([
    'upstream 500' => [['message' => 'private upstream data'], 500],
    'unauthorized' => [['message' => 'private upstream data'], 401],
    'invalid JSON' => ['<html>temporary failure</html>', 200],
    'unknown response wrapper' => [['message' => 'not a submissions list'], 200],
    'empty object' => ['{}', 200],
]);

test('Fluent connection errors preserve the failed page and expose no request secrets', function () {
    Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('credential-or-body-must-not-escape'));
    $response = $this->actingAs($this->owner)->postJson(route('websites.sync-fluent-form', $this->website), ['form_id' => 4]);
    $response->assertUnprocessable()->assertJsonPath('status', 'error')->assertJsonPath('synced', 0);
    expect($response->getContent())->not->toContain('credential-or-body-must-not-escape')->not->toContain('synthetic-password');
    expect($this->sync->progress($this->website, 4)['page'])->toBe(1);
});

test('unsupported Fluent routes fall back but valid empty lists complete the scan', function () {
    Http::fakeSequence()->push([], 404)->push(['entries' => []]);
    $result = $this->sync->syncNextPage($this->website, 4);
    expect($result['status'])->toBe('success');
    expect($this->sync->progress($this->website, 4))->toBeNull();
    expect($this->website->fresh()->last_sync_at->gt('2026-01-01'))->toBeTrue();
    Http::assertSentCount(2);
});

test('all unsupported Fluent routes fail instead of completing an empty history', function () {
    Http::fake(['*' => Http::response([], 404)]);
    expect(fn () => $this->sync->syncNextPage($this->website, 4))->toThrow(FluentSyncException::class);
    Http::assertSentCount(3);
    expect($this->website->fresh()->last_sync_at->format('Y-m-d'))->toBe('2026-01-01');
});

test('Fluent scans resume exactly the failed page and use the original page size', function () {
    $pages = [];
    $failed = false;
    Http::fake(function ($request) use (&$pages, &$failed) {
        $pages[] = [$request['page'], $request['per_page']];
        if ($request['page'] == 1) {
            return Http::response([fluentRecord(1), fluentRecord(2)]);
        }
        if (! $failed) {
            $failed = true;

            return Http::response([], 500);
        }

        return Http::response([fluentRecord(3)]);
    });
    expect($this->sync->syncNextPage($this->website, 4, 2)['status'])->toBe('partial');
    expect(fn () => $this->sync->syncNextPage($this->website, 4, 2))->toThrow(FluentSyncException::class);
    expect($this->sync->progress($this->website, 4)['page'])->toBe(2);
    expect($this->website->fresh()->last_sync_at->format('Y-m-d'))->toBe('2026-01-01');
    expect($this->sync->syncNextPage($this->website, 4, 100)['status'])->toBe('success');
    expect($pages)->toBe([[1, 2], [2, 2], [2, 2]]);
    expect(FfSubmission::count())->toBe(3);
    expect($this->website->fresh()->last_sync_at->gt('2026-01-01'))->toBeTrue();
});

test('known entries do not stop history repair and imports preserve richer existing data', function () {
    $existing = FfSubmission::create(['website_id' => $this->website->id, 'form_id' => 4, 'entry_id' => 1,
        'email' => 'webhook@example.test', 'payment_status' => 'paid', 'amount' => 25, 'pnr' => 'DEMO12',
        'payload' => ['response' => ['email' => 'webhook@example.test'], 'meta' => ['source' => 'webhook']]]);
    Http::fakeSequence()->push([fluentRecord(1)])->push([fluentRecord(2)])->push([]);
    expect($this->sync->syncNextPage($this->website, 4, 1))->toMatchArray(['status' => 'partial', 'synced' => 0]);
    expect($this->sync->syncNextPage($this->website, 4, 1))->toMatchArray(['status' => 'partial', 'synced' => 1]);
    expect($this->sync->syncNextPage($this->website, 4, 1)['status'])->toBe('success');
    expect(FfSubmission::count())->toBe(2);
    expect($existing->fresh()->only('email', 'payment_status', 'amount', 'pnr', 'payload'))->toBe($existing->only('email', 'payment_status', 'amount', 'pnr', 'payload'));
});

test('Fluent page parsing supports nested pagination and alternative IDs without duplicates', function () {
    $entry = fluentRecord(7);
    unset($entry['id']);
    $entry['entry_id'] = 7;
    $entry['response'] = json_encode(['email' => 'nested@example.test']);
    Http::fake(['*' => Http::response(['submissions' => ['data' => [$entry], 'last_page' => 2]])]);
    $service = app(FluentFormSubmissionService::class);
    expect($service->syncPage($this->website, 4, 1, 100))->toMatchArray(['count' => 1, 'has_more' => true]);
    expect($service->syncPage($this->website, 4, 1, 100))->toMatchArray(['count' => 0, 'has_more' => true]);
    expect(FfSubmission::sole()->email)->toBe('nested@example.test');
});

test('wrong form and invalid entry IDs fail the whole page without advancing', function (array $invalid) {
    Http::fake(['*' => Http::response([fluentRecord(1), $invalid])]);
    expect(fn () => $this->sync->syncNextPage($this->website, 4))->toThrow(FluentSyncException::class);
    expect(FfSubmission::count())->toBe(0);
    expect($this->sync->progress($this->website, 4)['page'])->toBe(1);
})->with([
    'other form' => [fluentRecord(2, ['form_id' => 99])],
    'invalid ID' => [fluentRecord(0)],
]);

test('repeated full pages fail without marking completion or entering an endless import', function () {
    Http::fake(['*' => Http::response([fluentRecord(1)])]);
    $this->sync->syncNextPage($this->website, 4, 1);
    expect(fn () => $this->sync->syncNextPage($this->website, 4, 1))->toThrow(FluentSyncException::class, 'repeated a page');
    expect($this->website->fresh()->last_sync_at->format('Y-m-d'))->toBe('2026-01-01');
    expect($this->sync->progress($this->website, 4)['page'])->toBe(2);
});

test('Fluent form locks prevent concurrent page processing', function () {
    $lock = Cache::lock("fluent_sync_v1:{$this->website->id}:4:lock", 60);
    $lock->get();
    try {
        expect(fn () => $this->sync->syncNextPage($this->website, 4))->toThrow(FluentSyncException::class, 'already running');
        Http::assertNothingSent();
    } finally {
        $lock->release();
    }
});

test('Fluent import endpoint processes one page at a time with the sync queue driver', function () {
    Queue::fake();
    Http::fakeSequence()->push([fluentRecord(1)])->push([]);
    $this->actingAs($this->owner)->postJson(route('websites.sync-fluent-form', $this->website), ['form_id' => 4, 'per_page' => 1])
        ->assertOk()->assertJsonPath('status', 'partial')->assertJsonPath('synced', 1);
    expect($this->website->fresh()->last_sync_at->format('Y-m-d'))->toBe('2026-01-01');
    $this->postJson(route('websites.sync-fluent-form', $this->website), ['form_id' => 4])->assertOk()->assertJsonPath('status', 'success');
    Http::assertSentCount(2);
    Queue::assertNothingPushed();
});

test('Fluent page loops can continue beyond the old three request throttle', function () {
    Http::fake(fn ($request) => Http::response($request['page'] <= 4 ? [fluentRecord((int) $request['page'])] : []));
    $this->actingAs($this->owner);
    for ($page = 1; $page <= 5; $page++) {
        $this->postJson(route('websites.sync-fluent-form', $this->website), ['form_id' => 4, 'per_page' => 1])
            ->assertOk()->assertJsonPath('status', $page <= 4 ? 'partial' : 'success');
    }
    expect(FfSubmission::count())->toBe(4);
    Http::assertSentCount(5);
});

test('scoped Fluent routes may omit form ID but global fallback must identify each form', function () {
    $entry = fluentRecord(7);
    unset($entry['form_id']);
    Http::fakeSequence()->push([$entry])->push([], 404)->push([], 404)->push([$entry]);
    expect($this->sync->syncNextPage($this->website, 4)['synced'])->toBe(1);
    expect(fn () => $this->sync->syncNextPage($this->website, 4))->toThrow(FluentSyncException::class, 'unidentified form');
    expect($this->sync->progress($this->website, 4)['page'])->toBe(1);
});

test('Fluent import endpoint protects ownership and validates page size', function () {
    $this->actingAs(User::factory()->create())->postJson(route('websites.sync-fluent-form', $this->website), ['form_id' => 4])->assertForbidden();
    $this->actingAs($this->owner)->postJson(route('websites.sync-fluent-form', $this->website), ['form_id' => 4, 'per_page' => 0])->assertUnprocessable();
    Http::assertNothingSent();
});

test('queued Fluent jobs schedule one continuation while sync jobs return partial without recursion', function () {
    Queue::fake();
    Http::fakeSequence()->push([fluentRecord(1)])->push([]);
    config(['queue.default' => 'database']);
    $job = new SyncFluentSubmissions($this->website->id, 4, 1);
    expect($job->handle()['status'])->toBe('partial');
    Queue::assertPushed(SyncFluentSubmissions::class, 1);
    expect($job->tries)->toBe(3);
    expect($job->timeout)->toBeLessThan(config('queue.connections.database.retry_after'));
    config(['queue.default' => 'sync']);
    expect($job->handle()['status'])->toBe('success');
    Queue::assertPushed(SyncFluentSubmissions::class, 1);
});

test('schema sync continues saved entries without fetching the schema on every page', function () {
    $this->mock(FluentFormSchemaService::class)->shouldReceive('syncFormSchema')->once()->andReturn(new FfForm(['title' => 'Demo form']));
    Http::fakeSequence()->push(['data' => [fluentRecord(1)], 'last_page' => 2])->push([]);
    $url = route('submissions.sync-form-schema', ['website' => $this->website->id, 'form_id' => 4]);
    $this->actingAs($this->owner)->postJson($url)->assertOk()->assertJsonPath('status', 'partial');
    $this->postJson($url)->assertOk()->assertJsonPath('status', 'success');
});

test('bulk schema sync never claims background entries with a synchronous queue', function () {
    Queue::fake();
    Http::fake(['*' => Http::response([['id' => 4]])]);
    $this->mock(FluentFormSchemaService::class)->shouldReceive('syncFormSchema')->once()->andReturn(new FfForm(['title' => 'Demo']));
    $this->actingAs($this->owner)->post(route('submissions.sync-all-schemas', $this->website))->assertSessionHas('success', fn ($message) => str_contains($message, 'Use Sync on Submissions'));
    Queue::assertNothingPushed();
});

test('bulk schema sync reports malformed form lists as errors', function ($body) {
    Http::fake(['*' => Http::response($body)]);
    $this->mock(FluentFormSchemaService::class)->shouldNotReceive('syncFormSchema');
    $this->actingAs($this->owner)->post(route('submissions.sync-all-schemas', $this->website))
        ->assertSessionHas('error')->assertSessionMissing('success');
})->with(['invalid JSON' => '<html>upstream error</html>', 'unknown wrapper' => [['message' => 'failure']], 'empty object' => '{}', 'invalid ID' => [[['id' => 0]]]]);

test('bulk schema sync handles every nested form and reports partial schema failures', function () {
    Queue::fake();
    Http::fake(['*' => Http::response(['forms' => ['data' => [['id' => 4], ['id' => 5]]]])]);
    $this->mock(FluentFormSchemaService::class)->shouldReceive('syncFormSchema')->once()->withArgs(fn ($website, $form) => $form === 4)->andReturn(new FfForm(['title' => 'Demo']));
    app(FluentFormSchemaService::class)->shouldReceive('syncFormSchema')->once()->withArgs(fn ($website, $form) => $form === 5)->andReturnNull();
    $this->actingAs($this->owner)->post(route('submissions.sync-all-schemas', $this->website))
        ->assertSessionHas('error', fn ($message) => str_contains($message, '1 form schema(s). 1 failed.'))->assertSessionMissing('success');
    Queue::assertNothingPushed();
});

test('Fluent CLI exits with failure for an incomplete scan and resumes on retry', function () {
    Http::fakeSequence()->push([fluentRecord(1)])->push([], 500)->push([]);
    $this->artisan('fluent:sync', ['website' => $this->website->id, 'form_id' => 4, '--per-page' => 1])->assertFailed();
    expect($this->website->fresh()->last_sync_at->format('Y-m-d'))->toBe('2026-01-01');
    expect($this->sync->progress($this->website, 4)['page'])->toBe(2);
    $this->artisan('fluent:sync', ['website' => $this->website->id, 'form_id' => 4, '--per-page' => 1])->assertSuccessful();
    expect(FfSubmission::count())->toBe(1);
});
