<?php

use App\Models\MarketingTemplate;
use App\Models\User;
use App\Models\Website;
use App\Services\MarketingContent;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->site = Website::create(['user_id' => $this->owner->id, 'name' => 'Travel Studio', 'slug' => 'travel-studio', 'base_url' => 'https://travel.example']);
    $this->actingAs($this->owner);
    $this->content = ['locale' => 'en', 'sender_email' => 'hello@travel.example', 'sender_name' => 'Travel Studio', 'reply_to' => 'hello@travel.example',
        'subject' => 'Plan your next trip', 'headline' => 'Your journey starts here', 'body' => "Hello,\n\nPlan your next trip with us.", 'signature' => 'The Travel Studio team',
        'logo_url' => 'https://travel.example/logo.png', 'accent_color' => '#b2cc85', 'cta_text' => 'Plan a trip', 'cta_url' => 'https://travel.example/book?route=return#dates',
        'layout' => 'studio', 'eyebrow' => 'Travel notes', 'highlight_title' => 'Before you book', 'highlight_body' => "Choose your route\nCheck your dates", 'postal_address' => ''];
});

test('branded templates save without a postal address and keep website ownership', function () {
    $this->post('/marketing/templates', $this->content + ['website_id' => $this->site->id, 'name' => 'Service introduction'])->assertRedirect();
    $template = MarketingTemplate::sole();
    expect($template->website_id)->toBe($this->site->id)->and($template->content['layout'])->toBe('studio')->and($template->content['postal_address'])->toBeNull();
    $other = Website::create(['user_id' => User::factory()->create()->id, 'name' => 'Other', 'slug' => 'other', 'base_url' => 'https://other.example']);
    $this->post('/marketing/templates', $this->content + ['website_id' => $other->id, 'name' => 'Other'])->assertForbidden();
    Http::assertNothingSent();
});

test('new layouts render escaped content and preserve unsubscribe and tracked booking links', function (string $layout) {
    $data = array_replace($this->content, ['layout' => $layout, 'eyebrow' => '<b>News</b>', 'highlight_title' => '<script>alert(1)</script>', 'highlight_body' => "<img src=x onerror=alert(1)>\nSecond item", 'body' => "<script>alert(2)</script>\n\nNext paragraph"]);
    $html = app(MarketingContent::class)->html($data, false, 87);
    expect($html)->toContain('role="presentation"', '@media only screen', '&lt;script&gt;', '&lt;img', '{{ unsubscribe }}', 'utm_campaign=campaign_87#dates', 'route=return&amp;utm_source=wphub', 'color:#101828', 'mailto:hello@travel.example')
        ->not->toContain('<script>', '<img src=x');
    $this->postJson('/marketing/preview', $data)->assertOk()->assertJsonPath('html', fn ($html) => str_contains($html, '<!doctype html>') && ! str_contains($html, '{{ unsubscribe }}'));
    Http::assertNothingSent();
})->with(['studio', 'letter']);

test('layout fields cannot inject template expressions or unsafe links', function () {
    foreach (['eyebrow', 'highlight_title', 'highlight_body'] as $field) {
        $this->postJson('/marketing/preview', array_replace($this->content, [$field => '{{ contact.SECRET }}']))->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    $this->postJson('/marketing/preview', array_replace($this->content, ['layout' => 'arbitrary-view']))->assertUnprocessable()->assertJsonValidationErrors('layout');
    $this->postJson('/marketing/preview', array_replace($this->content, ['logo_url' => 'javascript:alert(1)']))->assertUnprocessable()->assertJsonValidationErrors('logo_url');
});

test('dark brand colors retain readable buttons and optional panels disappear when empty', function () {
    $html = app(MarketingContent::class)->html(array_replace($this->content, ['accent_color' => '#101828', 'highlight_body' => '', 'logo_url' => '']));
    expect($html)->toContain('color:#ffffff', 'Travel Studio')->not->toContain('Before you book', '<img');
});

test('existing campaigns keep their classic layout without a saved layout selection', function () {
    $data = $this->content;
    unset($data['layout']);
    $html = app(MarketingContent::class)->html($data);
    expect($html)->toContain('border-top:5px solid', '{{ unsubscribe }}')->not->toContain('class="email-heading"');
});
