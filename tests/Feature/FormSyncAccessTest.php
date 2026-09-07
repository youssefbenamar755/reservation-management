<?php

use App\Jobs\SyncFluentFormEntries;
use App\Models\FfForm;
use App\Models\User;
use App\Models\Website;
use App\Services\FluentFormSchemaService;
use App\Services\FluentFormSubmissionService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery\MockInterface;

beforeEach(function () {
    config(['queue.default' => 'database']);
    Http::preventStrayRequests();
    Queue::fake();
    $this->owner = User::factory()->create();
    $this->website = Website::create([
        'user_id' => $this->owner->id,
        'name' => 'Owned website',
        'slug' => 'owned-website',
        'base_url' => 'https://owned.example',
        'ff_username' => 'test-user',
        'ff_app_password' => 'test-password',
    ]);
});

test('owners and admins can sync a form schema and its entries', function (bool $isAdmin) {
    $user = $isAdmin ? User::factory()->create(['is_admin' => true]) : $this->owner;

    $this->mock(FluentFormSchemaService::class, function (MockInterface $mock) {
        $mock->shouldReceive('syncFormSchema')
            ->once()
            ->withArgs(fn (Website $website, int $formId) => $website->is($this->website) && $formId === 4)
            ->andReturn(new FfForm(['title' => 'Test form']));
    });
    $this->mock(FluentFormSubmissionService::class, function (MockInterface $mock) {
        $mock->shouldReceive('syncPage')
            ->once()
            ->withArgs(fn (Website $website, int $formId, int $page) => $website->is($this->website) && $formId === 4 && $page === 1)
            ->andReturn(['count' => 2, 'has_more' => true]);
    });

    $this->actingAs($user)
        ->from('/submissions')
        ->post(route('submissions.sync-form-schema', ['website' => $this->website->id, 'form_id' => 4]))
        ->assertRedirect('/submissions')
        ->assertSessionHas('success');

    Queue::assertPushed(SyncFluentFormEntries::class);
    Http::assertNothingSent();
})->with(['owner' => false, 'admin' => true]);

test('owners and admins can sync all form schemas', function (bool $isAdmin) {
    $user = $isAdmin ? User::factory()->create(['is_admin' => true]) : $this->owner;

    Http::fake(['https://owned.example/wp-json/fluentform/v1/forms' => Http::response([['id' => 4]])]);
    $this->mock(FluentFormSchemaService::class, function (MockInterface $mock) {
        $mock->shouldReceive('syncFormSchema')
            ->once()
            ->withArgs(fn (Website $website, int $formId) => $website->is($this->website) && $formId === 4)
            ->andReturn(new FfForm(['title' => 'Test form']));
    });

    $this->actingAs($user)
        ->from('/submissions')
        ->post(route('submissions.sync-all-schemas', ['website' => $this->website->id]))
        ->assertRedirect('/submissions')
        ->assertSessionHas('success');

    Http::assertSentCount(1);
    Queue::assertPushed(SyncFluentFormEntries::class);
})->with(['owner' => false, 'admin' => true]);

test('other users cannot trigger form sync services or background jobs', function (string $route) {
    $this->mock(FluentFormSchemaService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('syncFormSchema'));
    $this->mock(FluentFormSubmissionService::class, fn (MockInterface $mock) => $mock->shouldNotReceive('syncPage'));

    $parameters = ['website' => $this->website->id];
    if ($route === 'submissions.sync-form-schema') {
        $parameters['form_id'] = 4;
    }

    $this->actingAs(User::factory()->create())
        ->post(route($route, $parameters))
        ->assertForbidden();

    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with(['submissions.sync-form-schema', 'submissions.sync-all-schemas']);
