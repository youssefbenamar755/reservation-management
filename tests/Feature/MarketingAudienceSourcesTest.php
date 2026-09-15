<?php

use App\Models\FfForm;
use App\Models\FfSubmission;
use App\Models\MarketingCampaign;
use App\Models\MarketingContact;
use App\Models\MarketingTemplate;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Services\MarketingAudience;
use App\Services\MarketingContactDiscovery;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->site = Website::create(['user_id' => $this->owner->id, 'name' => 'Forms site', 'slug' => 'forms-site', 'base_url' => 'https://forms.example']);
    $this->secondSite = Website::create(['user_id' => $this->owner->id, 'name' => 'Orders site', 'slug' => 'orders-site', 'base_url' => 'https://orders.example']);
    $this->actingAs($this->owner);
});

function audienceEntry(Website $site, array $overrides = []): FfSubmission
{
    return FfSubmission::create(array_replace(['website_id' => $site->id, 'form_id' => 1, 'entry_id' => FfSubmission::count() + 1,
        'email' => null, 'payload' => [], 'created_at_wp' => now()->subDay()], $overrides));
}

function audienceOrder(Website $site, string $email, array $overrides = []): WcOrder
{
    return WcOrder::create(array_replace(['website_id' => $site->id, 'wp_order_id' => WcOrder::count() + 1, 'customer_email' => $email,
        'status' => 'completed', 'currency' => 'EUR', 'total' => 20, 'payload' => [], 'created_at_wp' => now()], $overrides));
}

test('synced entries extract contact identity from supported Fluent Forms formats', function (array $values) {
    FfForm::create(['website_id' => $this->site->id, 'form_id' => 1, 'title' => 'Request', 'fields' => ['reply_address' => ['type' => 'input_email']]]);
    audienceEntry($this->site, $values);
    $contact = MarketingContact::sole();
    expect($contact->email)->toBe('lead@example.test')->and($contact->status)->toBe('unknown')
        ->and($contact->consented_at)->toBeNull()->and($contact->consent_source)->toBeNull();
    expect(DB::table('marketing_contact_submissions')->value('marketing_contact_id'))->toBe($contact->id);
    Http::assertNothingSent();
})->with([
    'stored email' => [['email' => ' LEAD@example.test ']],
    'webhook email' => [['payload' => ['Email' => ' LEAD@example.test ']]],
    'JSON response' => [['payload' => ['response' => json_encode(['email' => 'lead@example.test'])]]],
    'array response' => [['payload' => ['response' => ['email_address' => 'lead@example.test']]]],
    'input list' => [['payload' => ['inputs' => [['name' => 'email', 'value' => 'lead@example.test']]]]],
    'numbered field' => [['payload' => ['email_2' => 'lead@example.test']]],
    'cached schema' => [['payload' => ['response' => ['reply_address' => 'lead@example.test']]]],
]);

test('discovery skips missing invalid ambiguous and unrelated addresses', function (array $payload) {
    audienceEntry($this->site, ['payload' => $payload]);
    expect(MarketingContact::count())->toBe(0)->and(DB::table('marketing_contact_submissions')->count())->toBe(0);
})->with([
    'missing' => [[]],
    'invalid' => [['email' => 'not-an-email']],
    'array' => [['email' => ['lead@example.test']]],
    'ambiguous' => [['email_1' => 'first@example.test', 'email_2' => 'second@example.test']],
    'unrelated' => [['message' => 'lead@example.test', 'passengers' => [['email' => 'person@example.test']]]],
]);

test('form contacts and order customers merge once per website without merging website history', function () {
    $one = audienceEntry($this->site, ['payload' => ['email' => ' LEAD@example.test ', 'names' => ['first_name' => 'Ada', 'last_name' => 'Example']]]);
    audienceEntry($this->site, ['email' => 'lead@example.test']);
    audienceOrder($this->site, ' LEAD@example.test ');
    audienceOrder($this->site, 'lead@example.test', ['status' => 'failed']);
    audienceOrder($this->secondSite, 'lead@example.test');
    $audience = app(MarketingAudience::class);
    $rows = $audience->query([$this->site->id, $this->secondSite->id])->get()->keyBy('website_id');
    expect($rows)->toHaveCount(2)->and($rows[$this->site->id]->name)->toBe('Ada Example')
        ->and((int) $rows[$this->site->id]->orders_count)->toBe(2)->and((int) $rows[$this->site->id]->completed_count)->toBe(1)
        ->and((int) $rows[$this->site->id]->submissions_count)->toBe(2)
        ->and((int) $rows[$this->secondSite->id]->orders_count)->toBe(1)->and((int) $rows[$this->secondSite->id]->submissions_count)->toBe(0);
    $one->update(['pnr' => 'ABC123']);
    expect(DB::table('marketing_contact_submissions')->count())->toBe(2);
    Http::assertNothingSent();
});

test('historical refresh is repeatable fills missing names and preserves consent preferences and suppression', function () {
    $contact = MarketingContact::create(['website_id' => $this->site->id, 'email' => 'lead@example.test', 'name' => null, 'locale' => 'fr',
        'status' => 'unsubscribed', 'consent_source' => 'Original signup', 'consented_at' => now()->subYear(), 'unsubscribed_at' => now()->subMonth()]);
    $preferences = $contact->only(['locale', 'status', 'consent_source', 'consented_at', 'unsubscribed_at']);
    FfSubmission::withoutEvents(fn () => audienceEntry($this->site, ['payload' => ['email' => 'lead@example.test', 'names' => ['first_name' => 'Ada', 'last_name' => 'Example']]]));
    FfSubmission::withoutEvents(fn () => audienceEntry($this->site, ['email' => 'new@example.test']));
    WcOrder::withoutEvents(fn () => audienceOrder($this->site, 'new@example.test'));
    $discovery = app(MarketingContactDiscovery::class);
    expect($discovery->discover($this->site->id))->toBe(1)->and($discovery->discover($this->site->id))->toBe(0);
    expect($contact->fresh()->only(array_keys($preferences)))->toEqual($preferences)
        ->and($contact->fresh()->name)->toBe('Ada Example')->and(MarketingContact::count())->toBe(2)
        ->and(DB::table('marketing_contact_submissions')->count())->toBe(2);
    expect(MarketingContact::where('email', 'new@example.test')->sole()->status)->toBe('unknown');
    Http::assertNothingSent();
});

test('source filters distinguish forms orders leads and overlap', function (string $source, array $expected) {
    audienceEntry($this->site, ['email' => 'form@example.test']);
    audienceEntry($this->site, ['email' => 'both@example.test']);
    audienceOrder($this->site, 'both@example.test');
    audienceOrder($this->site, 'order@example.test');
    MarketingContact::create(['website_id' => $this->site->id, 'email' => 'manual@example.test']);
    // An order on another website must not disqualify this website's form lead.
    audienceOrder($this->secondSite, 'form@example.test');
    $rows = app(MarketingAudience::class)->query($this->site->id, ['source' => $source])->orderBy('marketing_contacts.email')->pluck('email')->all();
    expect($rows)->toBe($expected);
    $deliveryRows = app(MarketingAudience::class)->query($this->site->id, ['source' => $source], false)->orderBy('marketing_contacts.email')->pluck('email')->all();
    expect($deliveryRows)->toBe($expected);
})->with([
    ['forms', ['both@example.test', 'form@example.test']],
    ['orders', ['both@example.test', 'order@example.test']],
    ['forms_only', ['form@example.test']],
    ['orders_only', ['order@example.test']],
    ['both', ['both@example.test']],
    ['all', ['both@example.test', 'form@example.test', 'manual@example.test', 'order@example.test']],
]);

test('all website audience and discovery exclude websites belonging to other users', function () {
    $other = Website::create(['user_id' => User::factory()->create()->id, 'name' => 'Private', 'slug' => 'private', 'base_url' => 'https://private.example']);
    FfSubmission::withoutEvents(fn () => audienceEntry($other, ['email' => 'private@example.test']));
    FfSubmission::withoutEvents(fn () => audienceEntry($this->site, ['email' => 'lead@example.test']));
    WcOrder::withoutEvents(fn () => audienceOrder($this->secondSite, 'lead@example.test'));
    $this->post('/marketing/audience/discover')->assertSessionHasNoErrors();
    $page = $this->get('/marketing/audience')->assertOk()->viewData('page')['props'];
    expect($page['contacts']['total'])->toBe(2)->and($page['filters']['website_id'])->toBeNull()
        ->and($page['websiteSummary'])->toHaveCount(2)->and(json_encode($page))->not->toContain('private@example.test')
        ->and(MarketingContact::where('website_id', $other->id)->exists())->toBeFalse();
    $this->get('/marketing/audience?website_id='.$other->id)->assertForbidden();
    $this->post('/marketing/audience/discover', ['website_id' => $other->id])->assertForbidden();
    $this->get('/marketing/audience?source=invalid')->assertSessionHasErrors('source');
    Http::assertNothingSent();
});

test('audience counts follow filters while website overview retains full website totals', function () {
    audienceEntry($this->site, ['email' => 'form@example.test']);
    audienceOrder($this->site, 'order@example.test');
    $page = $this->get('/marketing/audience?website_id='.$this->site->id.'&source=forms_only')->viewData('page')['props'];
    expect($page['contacts']['total'])->toBe(1)->and($page['summary']['total'])->toBe(1)->and($page['summary']['unknown'])->toBe(1)
        ->and((int) data_get($page['websiteSummary'], $this->site->id.'.total'))->toBe(2)
        ->and((int) data_get($page['websiteSummary'], $this->site->id.'.forms'))->toBe(1)
        ->and((int) data_get($page['websiteSummary'], $this->site->id.'.orders'))->toBe(1);
});

test('website overlap alias is quoted for MySQL where BOTH is a reserved keyword', function () {
    $connection = DB::connection();
    $originalGrammar = $connection->getQueryGrammar();
    try {
        $connection->setQueryGrammar(new \Illuminate\Database\Query\Grammars\MySqlGrammar($connection));
        $connection->enableQueryLog();
        $this->get('/marketing/audience')->assertOk();
        $summaryQuery = collect($connection->getQueryLog())->pluck('query')->first(fn ($sql) => str_contains($sql, 'as forms'));
        expect($summaryQuery)->toContain('as `both`')->not->toContain('as both,');
    } finally {
        $connection->disableQueryLog();
        $connection->setQueryGrammar($originalGrammar);
    }
});

test('changing or deleting a submission updates its contact source without duplicating history', function () {
    $entry = audienceEntry($this->site, ['email' => 'old@example.test']);
    $entry->update(['email' => 'new@example.test', 'website_id' => $this->secondSite->id]);
    $audience = app(MarketingAudience::class);
    expect($audience->query($this->site->id, ['source' => 'forms'])->count())->toBe(0)
        ->and($audience->query($this->secondSite->id, ['source' => 'forms'])->sole()->email)->toBe('new@example.test');
    $entry->update(['email' => 'invalid']);
    expect($audience->query($this->secondSite->id, ['source' => 'forms'])->count())->toBe(0);
    $entry->update(['email' => 'new@example.test']);
    $entry->delete();
    expect(DB::table('marketing_contact_submissions')->count())->toBe(0);
});

test('campaign source selection controls eligibility and is rechecked if a lead places an order', function () {
    audienceEntry($this->site, ['email' => 'lead@example.test']);
    audienceEntry($this->site, ['email' => 'unknown@example.test']);
    audienceOrder($this->site, 'order@example.test');
    MarketingContact::where('email', '!=', 'unknown@example.test')->update(['status' => 'subscribed', 'consent_source' => 'Confirmed signup', 'consented_at' => now()->subDay()]);
    $template = MarketingTemplate::create(['user_id' => $this->owner->id, 'website_id' => $this->site->id, 'name' => 'Lead follow-up', 'content' => []]);
    $this->post('/marketing/campaigns', ['template_id' => $template->id, 'name' => 'Form leads', 'segment' => 'all', 'source' => 'forms_only'])->assertSessionHasNoErrors();
    $campaign = MarketingCampaign::sole();
    $audience = app(MarketingAudience::class);
    expect($campaign->audience['source'])->toBe('forms_only')->and($audience->review($campaign)['count'])->toBe(1)
        ->and($audience->stillEligible($campaign, 'unknown@example.test'))->toBeFalse()
        ->and($audience->stillEligible($campaign, 'lead@example.test'))->toBeTrue();
    audienceOrder($this->site, 'lead@example.test');
    expect($audience->stillEligible($campaign, 'lead@example.test'))->toBeFalse()->and($audience->review($campaign)['count'])->toBe(0);
    Http::assertNothingSent();
});

test('contact history command scopes the backfill and validates website input', function () {
    FfSubmission::withoutEvents(fn () => audienceEntry($this->site, ['email' => 'lead@example.test']));
    FfSubmission::withoutEvents(fn () => audienceEntry($this->secondSite, ['email' => 'other@example.test']));
    $this->artisan('marketing:discover', ['--website' => $this->site->id])->assertSuccessful();
    expect(MarketingContact::sole()->email)->toBe('lead@example.test');
    $this->artisan('marketing:discover', ['--website' => 'wrong'])->assertFailed();
    $this->artisan('marketing:discover')->assertSuccessful();
    expect(MarketingContact::count())->toBe(2);
    Http::assertNothingSent();
});

test('submission contact migration compiles on MySQL with short identifiers and cascading references', function () {
    $schema = Schema::getFacadeRoot();
    $mysql = new MySqlConnection(fn () => throw new RuntimeException('No database access allowed'), 'schema_preview', '', ['driver' => 'mysql', 'version' => '8.4.0', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci']);
    try {
        Schema::swap($mysql->getSchemaBuilder());
        $migration = require database_path('migrations/2026_09_15_000001_create_marketing_contact_submissions_table.php');
        $sql = implode("\n", array_column($mysql->pretend(fn () => $migration->up()), 'query'));
        expect($sql)->toContain('references `ff_submissions` (`id`) on delete cascade')->toContain('mcs_contact_fk');
        preg_match_all('/`([^`]+)`/', $sql, $matches);
        foreach (array_unique($matches[1]) as $identifier) {
            expect(strlen($identifier), $identifier)->toBeLessThanOrEqual(64);
        }
    } finally {
        Schema::swap($schema);
    }
});
