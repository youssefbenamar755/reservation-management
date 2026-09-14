<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingConnection;
use App\Models\MarketingContact;
use App\Models\MarketingTemplate;
use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Services\MarketingAudience;
use App\Services\MarketingContent;
use App\Services\MarketingDelivery;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Carbon::setTestNow('2026-09-14 12:00:00');
    $this->owner = User::factory()->create();
    $this->website = Website::create(['user_id' => $this->owner->id, 'name' => 'Demo Trips', 'slug' => 'demo-trips', 'base_url' => 'https://trips.example']);
    $this->connection = MarketingConnection::create(['user_id' => $this->owner->id, 'api_key' => 'test-brevo-private-key-123', 'connection_key' => (string) Str::uuid(),
        'webhook_secret' => str_repeat('a', 64), 'account_email' => 'manager@example.test', 'folder_id' => 10, 'webhook_id' => 20,
        'senders' => [['email' => 'news@trips.example', 'name' => 'Demo Trips']], 'verified_at' => now()]);
    $this->template = MarketingTemplate::create(['user_id' => $this->owner->id, 'website_id' => $this->website->id, 'name' => 'News', 'content' => marketingContentFixture()]);
    $this->sendCalls = 0;
    $this->sendStatus = 204;
    $this->accountStatus = 200;
    $this->webhookStatus = 204;
    $this->remoteStatus = 'draft';
    $this->beforeRemoteRead = null;
    Http::fake(function ($request) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if ($path === '/v3/account') {
            return Http::response(['email' => 'manager@example.test', 'message' => 'PRIVATE PROVIDER DETAIL'], $this->accountStatus);
        }
        if ($path === '/v3/senders') {
            return Http::response(['senders' => [['email' => 'news@trips.example', 'name' => 'Demo Trips', 'active' => true], ['email' => 'unverified@example.test', 'active' => false]]]);
        }
        if ($path === '/v3/contacts/folders') {
            return Http::response(['id' => 10], 201);
        }
        if ($path === '/v3/webhooks' && $request->method() === 'POST') {
            return Http::response(['id' => 20], 201);
        }
        if ($path === '/v3/webhooks/20') {
            return Http::response(null, $this->webhookStatus);
        }
        if ($path === '/v3/contacts/lists') {
            return Http::response(['id' => 30], 201);
        }
        if ($path === '/v3/contacts') {
            return Http::response(['id' => 99], 201);
        }
        if ($path === '/v3/contacts/lists/30/contacts/remove') {
            return Http::response(['contacts' => ['success' => $request['emails']]]);
        }
        if ($path === '/v3/emailCampaigns') {
            return Http::response(['id' => 40], 201);
        }
        if ($path === '/v3/emailCampaigns/40' && $request->method() === 'PUT') {
            return Http::response(null, 204);
        }
        if ($path === '/v3/emailCampaigns/40' && $request->method() === 'GET') {
            if ($this->beforeRemoteRead) {
                ($this->beforeRemoteRead)();
            }

            return Http::response(['id' => 40, 'status' => $this->remoteStatus]);
        }
        if ($path === '/v3/emailCampaigns/40/sendTest') {
            return Http::response(null, 204);
        }
        if ($path === '/v3/emailCampaigns/40/sendNow') {
            $this->sendCalls++;

            return $this->sendStatus === 0 ? Http::failedConnection('provider secret and private payload') : Http::response(null, $this->sendStatus);
        }
        throw new RuntimeException('Unexpected provider request: '.$request->method().' '.$path);
    });
    $this->actingAs($this->owner);
});

afterEach(fn () => Carbon::setTestNow());

function marketingContentFixture(array $overrides = []): array
{
    return array_replace(['locale' => 'fr', 'sender_email' => 'news@trips.example', 'sender_name' => 'Demo Trips', 'reply_to' => 'support@trips.example',
        'subject' => 'Votre prochain voyage', 'preheader' => 'Des nouvelles de notre équipe', 'headline' => 'Préparez votre prochain voyage',
        'body' => "Bonjour,\n\nDécouvrez nos nouveautés.", 'signature' => 'L’équipe Demo Trips', 'postal_address' => '123 Example Street, Demo City',
        'logo_url' => '', 'accent_color' => '#0f766e', 'cta_text' => 'Découvrir', 'cta_url' => 'https://trips.example/offers?source=site#booking'], $overrides);
}

function marketingSubscriber(Website $site, string $email = 'subscriber@example.test', array $overrides = []): MarketingContact
{
    return MarketingContact::create(array_replace(['website_id' => $site->id, 'email' => $email, 'name' => 'Demo Subscriber', 'locale' => 'fr', 'status' => 'subscribed', 'consent_source' => 'Confirmed signup record 42', 'consented_at' => now()->subDay()], $overrides));
}

function marketingDraft($test, array $overrides = []): MarketingCampaign
{
    return MarketingCampaign::create(array_replace(['user_id' => $test->owner->id, 'website_id' => $test->website->id, 'marketing_connection_id' => $test->connection->id,
        'connection_key' => $test->connection->connection_key, 'name' => 'September news', 'content' => $test->template->content, 'audience' => ['locale' => 'fr', 'segment' => 'all']], $overrides));
}

function scheduleMarketing($test, MarketingCampaign $campaign, ?string $time = null)
{
    $review = app(MarketingAudience::class)->review($campaign);

    return $test->post('/marketing/campaigns/'.$campaign->id.'/schedule', ['confirmed' => true, 'review_token' => $review['token'], 'scheduled_at' => $time]);
}

test('marketing pages require authentication and never call Brevo on GET', function () {
    foreach (['/marketing', '/marketing/audience', '/marketing/templates', '/settings/marketing'] as $url) {
        $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }
    Http::assertNothingSent();
    auth()->logout();
    $this->get('/marketing')->assertRedirect('/login');
});

test('marketing settings conceal encrypted keys and webhook credentials', function () {
    expect(DB::table('marketing_connections')->value('api_key'))->not->toContain('test-brevo-private-key');
    $page = $this->get('/settings/marketing')->viewData('page');
    expect(json_encode($page))->not->toContain('test-brevo-private-key')->not->toContain(str_repeat('a', 64))->not->toContain($this->connection->connection_key);
    $this->from('/settings/marketing')->post('/settings/marketing/connect', ['api_key' => "private\ninvalid-key"])->assertSessionHasErrors('api_key');
    expect(session()->getOldInput())->not->toHaveKey('api_key');
});

test('connecting provisions authenticated marketing events and keeps only verified senders', function () {
    $this->connection->update(['folder_id' => null, 'webhook_id' => null, 'verified_at' => null]);
    $this->post('/settings/marketing/connect', ['api_key' => 'replacement-private-api-key'])->assertSessionHasNoErrors()->assertSessionHas('success');
    expect($this->connection->fresh()->ready())->toBeTrue()->and($this->connection->fresh()->senders)->toHaveCount(1);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/webhooks') && $r['type'] === 'marketing' && $r['auth']['type'] === 'bearer' && strlen($r['auth']['token']) === 64);
    Http::assertNotSent(fn ($r) => preg_match('~/send(Test|Now)$~', $r->url()));
});

test('failed key validation preserves the prior connection and hides provider details', function () {
    $this->accountStatus = 401;
    $this->post('/settings/marketing/connect', ['api_key' => 'bad-replacement-api-key'])->assertSessionHasErrors('connection');
    expect($this->connection->fresh()->api_key)->toBe('test-brevo-private-key-123')->and(json_encode(session()->all()))->not->toContain('PRIVATE PROVIDER DETAIL');
});

test('an unconfirmed webhook refresh disables new campaign delivery until setup succeeds', function () {
    $this->webhookStatus = 503;
    $this->post('/settings/marketing/connect', [])->assertSessionHasErrors('connection');
    expect($this->connection->fresh()->ready())->toBeFalse();
    marketingSubscriber($this->website);
    $campaign = marketingDraft($this);
    scheduleMarketing($this, $campaign)->assertSessionHasErrors('campaign');
    expect($campaign->fresh()->status)->toBe('draft');
    $this->webhookStatus = 204;
    $this->post('/settings/marketing/connect', [])->assertSessionHasNoErrors();
    expect($this->connection->fresh()->ready())->toBeTrue();
    Http::assertNotSent(fn ($r) => preg_match('~/send(Test|Now)$~', $r->url()));
});

test('scheduling rejects an empty audience and unverified sender without provider calls', function () {
    $campaign = marketingDraft($this);
    scheduleMarketing($this, $campaign)->assertSessionHasErrors('campaign');
    marketingSubscriber($this->website);
    $campaign->update(['content' => marketingContentFixture(['sender_email' => 'unverified@example.test'])]);
    scheduleMarketing($this, $campaign)->assertSessionHasErrors('campaign');
    expect($campaign->fresh()->status)->toBe('draft')->and($campaign->recipients()->count())->toBe(0);
    Http::assertNothingSent();
});

test('website ownership and campaign ownership are independently enforced', function () {
    $other = User::factory()->create();
    $otherSite = Website::create(['user_id' => $other->id, 'name' => 'Other', 'slug' => 'other-site', 'base_url' => 'https://other.example']);
    $otherContact = marketingSubscriber($otherSite, 'other@example.test');
    $campaign = marketingDraft($this, ['user_id' => $other->id, 'website_id' => $otherSite->id]);
    $this->get('/marketing/audience?website_id='.$otherSite->id)->assertForbidden();
    $this->post('/marketing/websites/'.$otherSite->id.'/discover')->assertForbidden();
    $this->get('/marketing/campaigns/'.$campaign->id)->assertNotFound();
    $this->post('/marketing/campaigns/'.$campaign->id.'/cancel')->assertNotFound();
    expect($otherContact->fresh()->status)->toBe('subscribed');
    Http::assertNothingSent();
});

test('customer discovery normalizes duplicates without inferring consent or overwriting unsubscribe', function () {
    marketingSubscriber($this->website, 'old@example.test', ['status' => 'unsubscribed', 'unsubscribed_at' => now()->subHour()]);
    foreach ([' NEW@example.test ', 'new@example.test', 'old@example.test', 'not-an-email'] as $i => $email) {
        WcOrder::create(['website_id' => $this->website->id, 'wp_order_id' => $i + 1, 'customer_email' => $email, 'customer_name' => 'Customer', 'status' => 'completed', 'currency' => 'EUR', 'total' => 20, 'payload' => []]);
    }
    $this->post('/marketing/websites/'.$this->website->id.'/discover')->assertSessionHasNoErrors();
    expect(MarketingContact::count())->toBe(2)->and(MarketingContact::where('email', 'new@example.test')->first()->status)->toBe('unknown')
        ->and(MarketingContact::where('email', 'old@example.test')->first()->status)->toBe('unsubscribed');
    Http::assertNothingSent();
});

test('subscribing requires explicit evidence and cannot use a future date', function () {
    $url = '/marketing/websites/'.$this->website->id.'/contacts';
    $this->post($url, ['email' => 'sub@example.test', 'status' => 'subscribed'])->assertSessionHasErrors(['consent_source', 'consented_at', 'confirm_consent']);
    $data = ['email' => ' SUB@example.test ', 'status' => 'subscribed', 'locale' => 'en', 'consent_source' => 'Newsletter confirmation record', 'consented_at' => now()->addDay()->toIso8601String(), 'confirm_consent' => true];
    $this->post($url, $data)->assertSessionHasErrors('consented_at');
    $this->post($url, array_replace($data, ['consented_at' => now()->subDay()->toIso8601String()]))->assertSessionHasNoErrors();
    expect(MarketingContact::first()->email)->toBe('sub@example.test')->and(DB::table('marketing_consent_events')->count())->toBe(1);
});

test('CSV imports are atomic and default to unknown permission', function () {
    $url = '/marketing/websites/'.$this->website->id.'/import';
    $bad = UploadedFile::fake()->createWithContent('list.csv', "email,name,status,consent_source,consented_at\none@example.test,One,unknown,,\nbad-email,Two,unknown,,\n");
    $this->post($url, ['file' => $bad, 'confirm_consent' => true])->assertSessionHasErrors('file');
    expect(MarketingContact::count())->toBe(0);
    $good = UploadedFile::fake()->createWithContent('list.csv', "email,name\nONE@example.test,One\n");
    $this->post($url, ['file' => $good, 'confirm_consent' => true])->assertSessionHasNoErrors();
    expect(MarketingContact::first()->status)->toBe('unknown');
    Http::assertNothingSent();
});

test('CSV imports cannot revive unsubscribed addresses and preserve preferences for unknown rows', function () {
    $contact = marketingSubscriber($this->website, 'one@example.test', ['status' => 'unsubscribed', 'unsubscribed_at' => now()->subDay()]);
    $file = UploadedFile::fake()->createWithContent('list.csv', "email,status,consent_source,consented_at\none@example.test,subscribed,Confirmation record,2026-09-14T10:00:00Z\n");
    $this->post('/marketing/websites/'.$this->website->id.'/import', ['file' => $file, 'confirm_consent' => true])->assertSessionHasErrors('consent_source');
    expect($contact->fresh()->status)->toBe('unsubscribed');
    $file = UploadedFile::fake()->createWithContent('list.csv', "email\none@example.test\n");
    $this->post('/marketing/websites/'.$this->website->id.'/import', ['file' => $file, 'confirm_consent' => true])->assertSessionHasNoErrors();
    expect($contact->fresh()->status)->toBe('unsubscribed');
});

test('new individual consent must postdate an unsubscribe', function () {
    $contact = marketingSubscriber($this->website, 'one@example.test', ['status' => 'unsubscribed', 'unsubscribed_at' => now()->subHours(2)]);
    $data = ['email' => $contact->email, 'status' => 'subscribed', 'consent_source' => 'New confirmation record', 'consented_at' => now()->subDay()->toIso8601String(), 'confirm_consent' => true];
    $this->post('/marketing/websites/'.$this->website->id.'/contacts', $data)->assertSessionHasErrors('consent_source');
    $this->post('/marketing/websites/'.$this->website->id.'/contacts', array_replace($data, ['consented_at' => now()->subHour()->toIso8601String()]))->assertSessionHasNoErrors();
    expect($contact->fresh()->status)->toBe('subscribed');
});

test('template preview escapes HTML and rejects unsafe URLs and provider template expressions', function () {
    $this->postJson('/marketing/preview', marketingContentFixture(['body' => '<script>alert(1)</script>']))->assertOk()->assertJsonPath('html', fn ($html) => str_contains($html, '&lt;script&gt;') && ! str_contains($html, '<script>'));
    $this->postJson('/marketing/preview', marketingContentFixture(['cta_url' => 'javascript:alert(1)']))->assertUnprocessable()->assertJsonValidationErrors('cta_url');
    $this->postJson('/marketing/preview', marketingContentFixture(['body' => '{{ contact.SECRET }}']))->assertUnprocessable()->assertJsonValidationErrors('body');
    $html = app(MarketingContent::class)->html(marketingContentFixture(), false, 42);
    expect($html)->toContain('{{ unsubscribe }}')->toContain('utm_campaign=campaign_42#booking')->toContain('source=site&amp;utm_source=wphub');
    Http::assertNothingSent();
});

test('campaign creation copies template content without sending or subscribing anyone', function () {
    $this->post('/marketing/campaigns', ['template_id' => $this->template->id, 'name' => 'Launch', 'locale' => 'fr', 'segment' => 'all'])->assertRedirect();
    $campaign = MarketingCampaign::first();
    $this->template->update(['content' => marketingContentFixture(['subject' => 'Changed later'])]);
    expect($campaign->fresh()->content['subject'])->toBe('Votre prochain voyage')->and($campaign->status)->toBe('draft');
    Http::assertNothingSent();
});

test('audience excludes unknown consent, other brands, language mismatches and account suppressions', function () {
    $campaign = marketingDraft($this);
    marketingSubscriber($this->website, 'yes@example.test');
    marketingSubscriber($this->website, 'unknown@example.test', ['status' => 'unknown']);
    marketingSubscriber($this->website, 'withdrawn@example.test', ['status' => 'unsubscribed']);
    marketingSubscriber($this->website, 'english@example.test', ['locale' => 'en']);
    marketingSubscriber($this->website, 'blocked@example.test');
    DB::table('marketing_suppressions')->insert(['marketing_connection_id' => $this->connection->id, 'email' => 'blocked@example.test', 'reason' => 'spam', 'created_at' => now(), 'updated_at' => now()]);
    $other = Website::create(['user_id' => $this->owner->id, 'name' => 'Second', 'slug' => 'second', 'base_url' => 'https://second.example']);
    marketingSubscriber($other, 'other@example.test');
    expect(app(MarketingAudience::class)->review($campaign)['rows']->pluck('email')->all())->toBe(['yes@example.test']);
});

test('repeat audience counts only completed orders on the selected website', function () {
    marketingSubscriber($this->website);
    foreach (['completed', 'failed', 'completed'] as $i => $status) {
        WcOrder::create(['website_id' => $this->website->id, 'wp_order_id' => $i + 1, 'customer_email' => 'SUBSCRIBER@example.test', 'status' => $status, 'currency' => 'EUR', 'total' => 10, 'created_at_wp' => now()->subDay(), 'payload' => []]);
    }
    $campaign = marketingDraft($this, ['audience' => ['segment' => 'repeat']]);
    expect(app(MarketingAudience::class)->review($campaign)['count'])->toBe(1);
    $campaign->audience = ['segment' => 'first'];
    expect(app(MarketingAudience::class)->review($campaign)['count'])->toBe(0);
});

test('scheduling requires confirmation, rejects stale audiences, and freezes recipient membership', function () {
    marketingSubscriber($this->website, 'one@example.test');
    $campaign = marketingDraft($this);
    $review = app(MarketingAudience::class)->review($campaign);
    $url = '/marketing/campaigns/'.$campaign->id.'/schedule';
    $this->post($url, ['review_token' => $review['token']])->assertSessionHasErrors('confirmed');
    marketingSubscriber($this->website, 'two@example.test');
    $this->post($url, ['review_token' => $review['token'], 'confirmed' => true])->assertSessionHasErrors('campaign');
    scheduleMarketing($this, $campaign, now()->addHour()->toIso8601String())->assertSessionHasNoErrors();
    marketingSubscriber($this->website, 'later@example.test');
    expect($campaign->recipients()->count())->toBe(2)->and($campaign->fresh()->status)->toBe('scheduled');
    scheduleMarketing($this, $campaign)->assertSessionHasErrors('campaign');
    expect($campaign->recipients()->count())->toBe(2);
    Http::assertNothingSent();
});

test('draft preview created before connection remains read only and attaches on scheduling', function () {
    marketingSubscriber($this->website);
    $campaign = marketingDraft($this, ['marketing_connection_id' => null, 'connection_key' => null]);
    $page = $this->get('/marketing/campaigns/'.$campaign->id)->assertOk()->viewData('page');
    expect($campaign->fresh()->marketing_connection_id)->toBeNull();
    $this->post('/marketing/campaigns/'.$campaign->id.'/schedule', ['confirmed' => true, 'review_token' => $page['props']['review']['token']])->assertSessionHasNoErrors();
    expect($campaign->fresh()->marketing_connection_id)->toBe($this->connection->id);
});

test('future schedules and idle scheduler do not call providers', function () {
    marketingSubscriber($this->website);
    $campaign = marketingDraft($this);
    scheduleMarketing($this, $campaign, now()->addHour()->toIso8601String())->assertSessionHasNoErrors();
    $this->artisan('marketing:process')->assertSuccessful();
    Http::assertNothingSent();
});

test('bounded preparation submits exactly once and does not clear provider blacklisting', function () {
    marketingSubscriber($this->website);
    $campaign = marketingDraft($this);
    scheduleMarketing($this, $campaign)->assertSessionHasNoErrors();
    $this->artisan('marketing:process')->assertSuccessful();
    $this->artisan('marketing:process')->assertSuccessful();
    app(MarketingDelivery::class)->step($campaign->fresh());
    expect($this->sendCalls)->toBe(1)->and($campaign->fresh()->status)->toBe('submitted');
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/contacts') && $r['email'] === 'subscriber@example.test' && ! array_key_exists('emailBlacklisted', $r->data()));
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/emailCampaigns') && str_contains($r['htmlContent'], '{{ unsubscribe }}'));
});

test('withdrawal during preparation removes recipients and cancels empty sends', function () {
    $contact = marketingSubscriber($this->website);
    $campaign = marketingDraft($this);
    scheduleMarketing($this, $campaign)->assertSessionHasNoErrors();
    $this->artisan('marketing:process', ['--steps' => 2])->assertSuccessful();
    $contact->update(['status' => 'unsubscribed', 'unsubscribed_at' => now()]);
    $this->artisan('marketing:process')->assertSuccessful();
    expect($campaign->fresh()->status)->toBe('cancelled')->and($this->sendCalls)->toBe(0);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/contacts/remove') && $r['emails'] === ['subscriber@example.test']);
});

test('withdrawal arriving during the final provider check still prevents sending', function () {
    $contact = marketingSubscriber($this->website);
    $campaign = marketingDraft($this);
    scheduleMarketing($this, $campaign)->assertSessionHasNoErrors();
    $this->beforeRemoteRead = fn () => $contact->update(['status' => 'unsubscribed', 'unsubscribed_at' => now()]);
    $this->artisan('marketing:process')->assertSuccessful();
    expect($this->sendCalls)->toBe(0)->and($campaign->fresh()->status)->toBe('cancelled');
});

test('ambiguous sends are never retried automatically', function () {
    marketingSubscriber($this->website);
    $campaign = marketingDraft($this);
    scheduleMarketing($this, $campaign)->assertSessionHasNoErrors();
    $this->sendStatus = 0;
    $this->artisan('marketing:process')->assertSuccessful();
    $this->artisan('marketing:process')->assertSuccessful();
    expect($this->sendCalls)->toBe(1)->and($campaign->fresh()->status)->toBe('uncertain')->and($campaign->fresh()->result_message)->not->toContain('private payload');
});

test('definite provider rejections stop the campaign without retrying', function () {
    marketingSubscriber($this->website);
    $campaign = marketingDraft($this);
    scheduleMarketing($this, $campaign)->assertSessionHasNoErrors();
    $this->sendStatus = 400;
    $this->artisan('marketing:process')->assertSuccessful();
    $this->artisan('marketing:process')->assertSuccessful();
    expect($this->sendCalls)->toBe(1)->and($campaign->fresh()->status)->toBe('failed');
});

test('campaign already sent or modified to scheduled in Brevo is not sent again', function () {
    marketingSubscriber($this->website);
    $campaign = marketingDraft($this);
    scheduleMarketing($this, $campaign)->assertSessionHasNoErrors();
    $this->remoteStatus = 'sent';
    $this->artisan('marketing:process')->assertSuccessful();
    expect($this->sendCalls)->toBe(0)->and($campaign->fresh()->status)->toBe('uncertain');
});

test('cancellation and disconnected credentials stop queued work', function () {
    marketingSubscriber($this->website);
    $campaign = marketingDraft($this);
    scheduleMarketing($this, $campaign)->assertSessionHasNoErrors();
    $this->post('/marketing/campaigns/'.$campaign->id.'/cancel')->assertSessionHasNoErrors();
    $this->artisan('marketing:process')->assertSuccessful();
    expect($campaign->fresh()->status)->toBe('cancelled');
    $other = marketingDraft($this);
    scheduleMarketing($this, $other)->assertSessionHasNoErrors();
    $this->delete('/settings/marketing/connection')->assertSessionHasNoErrors();
    $this->artisan('marketing:process')->assertSuccessful();
    expect($other->fresh()->status)->toBe('cancelled')->and($this->connection->fresh()->api_key)->toBeNull();
    Http::assertNothingSent();
});

test('revoked website access prevents scheduled sending', function () {
    marketingSubscriber($this->website);
    $campaign = marketingDraft($this);
    scheduleMarketing($this, $campaign)->assertSessionHasNoErrors();
    $this->website->update(['user_id' => User::factory()->create()->id]);
    $this->artisan('marketing:process')->assertSuccessful();
    expect($campaign->fresh()->status)->toBe('failed');
    Http::assertNothingSent();
});

test('test sending uses exactly the connection account address and never sends the campaign', function () {
    $campaign = marketingDraft($this);
    $this->post('/marketing/campaigns/'.$campaign->id.'/test', ['confirmed' => true, 'emailTo' => ['victim@example.test']])->assertSessionHasNoErrors();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/sendTest') && $r['emailTo'] === ['manager@example.test']);
    expect($this->sendCalls)->toBe(0)->and($campaign->fresh()->status)->toBe('draft')->and($campaign->fresh()->test_sent_at)->not->toBeNull();
});

test('webhook authentication rejects forged suppression', function () {
    $url = '/api/marketing/events/'.$this->connection->id;
    $this->postJson($url, ['email' => 'one@example.test', 'event' => 'unsubscribe'])->assertForbidden();
    $this->withToken(str_repeat('b', 64))->postJson($url, ['email' => 'one@example.test', 'event' => 'unsubscribe'])->assertForbidden();
    expect(DB::table('marketing_suppressions')->count())->toBe(0);
});

test('duplicate and out of order events count each recipient once without retaining payloads', function () {
    marketingSubscriber($this->website);
    $campaign = marketingDraft($this);
    scheduleMarketing($this, $campaign)->assertSessionHasNoErrors();
    $this->artisan('marketing:process')->assertSuccessful();
    $url = '/api/marketing/events/'.$this->connection->id;
    $event = ['email' => 'subscriber@example.test', 'event' => 'click', 'camp_id' => 40, 'ts_event' => now()->timestamp, 'link' => 'https://private.example/path', 'ip' => '203.0.113.1'];
    $this->withToken(str_repeat('a', 64))->postJson($url, $event)->assertNoContent();
    $this->postJson($url, $event)->assertNoContent();
    $this->postJson($url, array_replace($event, ['ts_event' => now()->subMinute()->timestamp]))->assertNoContent();
    expect(app(MarketingDelivery::class)->stats($campaign)['clicked'])->toBe(1)->and($campaign->recipients()->first()->clicked_at->timestamp)->toBe(now()->subMinute()->timestamp)
        ->and(json_encode($campaign->recipients()->first()->toArray()))->not->toContain('private.example')->not->toContain('203.0.113.1');
});

test('unsubscribe events suppress future campaigns across the same connection and preserve other website consent', function () {
    $contact = marketingSubscriber($this->website);
    $campaign = marketingDraft($this, ['provider_campaign_id' => 40]);
    $second = Website::create(['user_id' => $this->owner->id, 'name' => 'Second', 'slug' => 'second', 'base_url' => 'https://second.example']);
    $other = marketingSubscriber($second);
    $this->withToken(str_repeat('a', 64))->postJson('/api/marketing/events/'.$this->connection->id, ['event' => 'unsubscribe', 'email' => 'SUBSCRIBER@example.test', 'camp_id' => 40, 'ts_event' => now()->timestamp])->assertNoContent();
    expect($contact->fresh()->status)->toBe('unsubscribed')->and($other->fresh()->status)->toBe('subscribed')
        ->and(DB::table('marketing_suppressions')->count())->toBe(1)->and(app(MarketingAudience::class)->review(marketingDraft($this, ['website_id' => $second->id]))['count'])->toBe(0);
});

test('an interrupted sending claim becomes uncertain and is never retried', function () {
    $campaign = marketingDraft($this, ['status' => 'sending', 'sending_at' => now()->subMinutes(11)]);
    $this->artisan('marketing:process')->assertSuccessful();
    expect($campaign->fresh()->status)->toBe('uncertain');
    Http::assertNothingSent();
});
