<?php

use App\Models\User;
use App\Models\WcOrder;
use App\Models\Website;
use App\Models\WebsiteDocumentProfile;
use App\Services\BrandedDocumentRenderer;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->reviewWebsite = Website::create([
        'user_id' => User::factory()->create()->id, 'name' => 'Example Travel',
        'slug' => 'review-render-fixture', 'base_url' => 'https://reservationpourvisa.com', 'status' => 'active',
    ]);
    $this->reviewProfile = (new WebsiteDocumentProfile([
        'website_id' => $this->reviewWebsite->id, 'brand_name' => 'Example Travel',
        'website_url' => $this->reviewWebsite->base_url, 'support_email' => 'support@example.test',
        'primary_color' => '#143e50', 'accent_color' => '#248070', 'background_color' => '#f5f7fa', 'text_color' => '#102030',
        'footer' => 'The {{brand_name}} team', 'service_label' => 'reservation',
        'templates' => BrandedDocumentRenderer::defaults(), 'default_language' => 'fr',
        'trustpilot_url' => 'https://www.trustpilot.com/review/reservationpourvisa.com',
    ]))->setRelation('website', $this->reviewWebsite);
});

test('document review invitation uses the exact website in both language and template variants', function (string $domain, string $language, string $template) {
    $this->reviewWebsite->update(['base_url' => 'https://'.$domain]);
    $this->reviewProfile->trustpilot_url = 'https://www.trustpilot.com/review/'.$domain;
    $result = app(BrandedDocumentRenderer::class)->render($this->reviewProfile,
        ['customer_first_name' => 'Alex', 'order_number' => 'DEMO-42'], [['name' => 'sample.pdf']], $template, $language);
    $target = 'https://www.trustpilot.com/evaluate/'.$domain;
    $button = $language === 'fr' ? 'Donner mon avis sur Trustpilot' : 'Review us on Trustpilot';
    $dom = new DOMDocument;
    @$dom->loadHTML($result['html']);
    $links = (new DOMXPath($dom))->query('//a[@href="'.$target.'"]');
    expect($links->length)->toBe(1)
        ->and($result['html'])->toContain($button, 'sample.pdf')
        ->and($result['text'])->toContain($button.': '.$target)
        ->and(substr_count($result['text'], $target))->toBe(1);
    Http::assertNothingSent();
})->with(['reservationpourvisa.com', 'reservationvisa.com', 'pre-reservation.fr'])->with(['fr', 'en'])->with(['delivery', 'correction']);

test('missing unsafe or another brand review profile never leaks into email', function (?string $url) {
    $this->reviewProfile->trustpilot_url = $url;
    // An editable signature URL must not override the registered website identity.
    $this->reviewProfile->website_url = 'https://reservationvisa.com';
    $result = app(BrandedDocumentRenderer::class)->render($this->reviewProfile,
        ['customer_first_name' => 'Alex', 'order_number' => 'DEMO-42'], [['name' => 'sample.pdf']]);
    expect($result['html'])->not->toContain('Trustpilot', 'trustpilot.com', 'javascript:')
        ->and($result['text'])->not->toContain('Trustpilot', 'trustpilot.com', 'javascript:')
        ->and($result['html'])->toContain('sample.pdf', 'Example Travel');
    Http::assertNothingSent();
})->with([null, '', 'https://www.trustpilot.com/review/reservationvisa.com', 'https://trustpilot.com.evil.example/review/reservationpourvisa.com', 'javascript:alert(1)']);

test('review invitation stays out of acknowledgement and empty document previews', function () {
    $renderer = app(BrandedDocumentRenderer::class);
    foreach ([['acknowledgement', [['name' => 'sample.pdf']]], ['delivery', []], ['correction', []]] as [$template, $files]) {
        $result = $renderer->render($this->reviewProfile, ['customer_first_name' => 'Alex', 'order_number' => 'DEMO-42'], $files, $template, 'en');
        expect($result['html'])->not->toContain('Trustpilot')
            ->and($result['text'])->not->toContain('trustpilot.com');
    }
});

test('review copy escapes brand text and follows the recorded order language', function () {
    $this->reviewProfile->brand_name = '<img src=x onerror=alert(1)>';
    $order = new WcOrder(['website_id' => $this->reviewWebsite->id, 'wp_order_id' => 42, 'payload' => ['locale' => 'en_US']]);
    $result = app(BrandedDocumentRenderer::class)->render($this->reviewProfile, $order, [['name' => 'sample.pdf']], 'delivery', 'fr');
    expect($result['language'])->toBe('en')
        ->and($result['html'])->toContain('Review us on Trustpilot', '&lt;img src=x')
        ->not->toContain('<img src=x');
});
