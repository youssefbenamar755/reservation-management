<?php

use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteDocumentProfile;
use App\Services\BrandedDocumentRenderer;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Http::preventStrayRequests();
    $website = Website::create(['user_id' => User::factory()->create()->id,
        'name' => 'Example Travel', 'slug' => 'email-design', 'base_url' => 'https://reservationvisa.com', 'status' => 'active']);
    $this->designProfile = new WebsiteDocumentProfile([
        'website_id' => $website->id, 'brand_name' => 'Example Travel', 'website_url' => $website->base_url,
        'logo_url' => 'https://reservationvisa.com/logo.png', 'support_email' => 'support@example.test',
        'primary_color' => '#334155', 'accent_color' => '#00cc61', 'background_color' => '#f3f4f6', 'text_color' => '#18212f',
        'footer' => "{{brand_name}}\n{{support_email}}\n{{website_url}}\nKeep this custom footer note.",
        'service_label' => 'reservation', 'templates' => BrandedDocumentRenderer::defaults(), 'default_language' => 'en',
        'trustpilot_url' => 'https://www.trustpilot.com/review/reservationvisa.com',
    ]);
});

test('review button remains readable with every live brand accent', function (string $accent) {
    $this->designProfile->accent_color = $accent;
    $rendered = app(BrandedDocumentRenderer::class)->render($this->designProfile,
        ['customer_first_name' => 'Alex', 'order_number' => 'DESIGN-1'], [['name' => 'sample.pdf']], 'delivery', 'en');
    $dom = new DOMDocument;
    @$dom->loadHTML($rendered['html']);
    $buttons = (new DOMXPath($dom))->query('//a[@href="https://www.trustpilot.com/evaluate/reservationvisa.com"]');
    expect($buttons->length)->toBe(1);
    $button = $buttons->item(0);
    preg_match('/(?:^|;)\s*color:\s*(#[a-f0-9]{6})/i', $button->getAttribute('style'), $foreground);
    expect($foreground)->toHaveCount(2);
    expect(BrandedDocumentRenderer::contrastRatio($foreground[1], $accent))->toBeGreaterThanOrEqual(4.5);
    Http::assertNothingSent();
})->with(['#4151c9', '#cd1327', '#00cc61']);

test('footer keeps custom information without repeating the same contact text', function () {
    $rendered = app(BrandedDocumentRenderer::class)->render($this->designProfile,
        ['customer_first_name' => 'Alex', 'order_number' => 'DESIGN-2'], [['name' => 'sample.pdf']], 'delivery', 'en');
    $dom = new DOMDocument;
    @$dom->loadHTML($rendered['html']);
    $text = $dom->getElementsByTagName('body')->item(0)->textContent;
    expect(substr_count($text, 'support@example.test'))->toBe(1)
        ->and(substr_count($text, 'Keep this custom footer note.'))->toBe(1);
    Http::assertNothingSent();
});

test('designed emails keep user copy literal and long document names intact', function () {
    $templates = BrandedDocumentRenderer::defaults();
    $templates['delivery']['en']['body'] = "Hello {{customer_first_name}},\n\n<script>alert('body')</script>\n\n{{brand_name}}";
    $this->designProfile->templates = $templates;
    $this->designProfile->brand_name = 'Example <script>alert(1)</script>';
    $this->designProfile->footer = '<img src=x onerror=alert(1)> Custom footer';
    $name = str_repeat('LongName', 16).'.pdf';
    $rendered = app(BrandedDocumentRenderer::class)->render($this->designProfile,
        ['customer_first_name' => '<b>Alex</b>', 'order_number' => '<iframe>'], [['name' => $name]], 'delivery', 'en');
    $dom = new DOMDocument;
    @$dom->loadHTML($rendered['html']);
    $xpath = new DOMXPath($dom);
    expect($xpath->query('//script | //iframe | //*[@onerror]')->length)->toBe(0)
        ->and($rendered['html'])->toContain('&lt;b&gt;Alex&lt;/b&gt;', '&lt;script&gt;', $name)
        ->and($rendered['text'])->toContain($name, 'Custom footer');
    Http::assertNothingSent();
});

test('an email address in a custom footer does not hide the website link', function () {
    $this->designProfile->footer = 'Contact support@reservationvisa.com for assistance.';
    $this->designProfile->support_email = 'support@reservationvisa.com';
    $rendered = app(BrandedDocumentRenderer::class)->render($this->designProfile,
        ['customer_first_name' => 'Alex', 'order_number' => 'DESIGN-3'], [['name' => 'sample.pdf']], 'delivery', 'en');
    $dom = new DOMDocument;
    @$dom->loadHTML($rendered['html']);
    expect((new DOMXPath($dom))->query('//a[@href="https://reservationvisa.com"]')->length)->toBe(1);
});

test('valid email punctuation stays inside the mailto address', function () {
    $this->designProfile->footer = 'Contact our team.';
    $this->designProfile->support_email = 'support?subject=other#tag@example.test';
    $rendered = app(BrandedDocumentRenderer::class)->render($this->designProfile,
        ['customer_first_name' => 'Alex', 'order_number' => 'DESIGN-4'], [['name' => 'sample.pdf']], 'delivery', 'en');
    $dom = new DOMDocument;
    @$dom->loadHTML($rendered['html']);
    $href = (new DOMXPath($dom))->query('//a[starts-with(@href, "mailto:")]')->item(0)->getAttribute('href');
    expect(rawurldecode(substr($href, 7)))->toBe($this->designProfile->support_email)
        ->and($href)->not->toContain('?', '#');
});
