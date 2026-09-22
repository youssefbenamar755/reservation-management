<?php

use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
});

test('documentation and its download require a signed in user', function () {
    $this->get('/documentation')->assertRedirect('/login');
    $this->get('/documentation/download')->assertRedirect('/login');
});

test('a normal user can read both guides with unique navigable chapters', function () {
    $this->actingAs(User::factory()->create(['is_admin' => false]))
        ->get('/documentation')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('Documentation/Index')
        ->where('chapters', function ($chapters) {
            $chapters = collect($chapters);
            expect($chapters->count())->toBeGreaterThanOrEqual(25);
            expect($chapters->pluck('id')->unique())->toHaveCount($chapters->count());
            expect($chapters->pluck('group')->unique()->values()->all())->toBe(['User guide', 'Owner & handover']);
            $orders = $chapters->firstWhere('id', 'user-orders-and-transaction-search');
            expect($orders['html'])->toContain('<ol>', '<strong>transaction ID</strong>');
            expect($chapters->firstWhere('id', 'owner-transfer-to-a-future-owner')['text'])->toContain('handover');

            return true;
        }));
});

test('download contains the complete user and owner guides as an attachment', function () {
    $this->actingAs(User::factory()->create())->get('/documentation/download')->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="WP-Hub-Guide.md"')
        ->assertHeader('Content-Type', 'text/markdown; charset=UTF-8')
        ->assertSee('# WP Hub user guide', false)
        ->assertSee('# WP Hub owner and handover guide', false)
        ->assertSee('## Troubleshooting checklist', false)
        ->assertSee('## Transfer to a future owner', false);
});
