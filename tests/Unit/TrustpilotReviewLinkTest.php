<?php

use App\Support\TrustpilotReviewLink;

test('Trustpilot links normalize only to the website’s own canonical profile', function (string $url, string $website) {
    expect(TrustpilotReviewLink::normalize($url, $website))->toBe('https://www.trustpilot.com/review/travel.example');
})->with([
    ['https://www.trustpilot.com/review/travel.example', 'https://travel.example'],
    ['https://fr.trustpilot.com/review/www.travel.example/', 'https://www.travel.example'],
    ['https://trustpilot.com/evaluate/TRAVEL.EXAMPLE', 'https://TRAVEL.EXAMPLE/store'],
    ['https://www.trustpilot.com/evaluate/travel.example/', 'https://www.travel.example'],
]);

test('invalid and cross-brand Trustpilot links are omitted', function (?string $url, ?string $website) {
    expect(TrustpilotReviewLink::normalize($url, $website))->toBeNull();
})->with([
    [null, 'https://travel.example'],
    ['', 'https://travel.example'],
    ['https://www.trustpilot.com/review/travel.example', null],
    ['http://www.trustpilot.com/review/travel.example', 'https://travel.example'],
    ['//www.trustpilot.com/review/travel.example', 'https://travel.example'],
    ['https://www.trustpilot.com.evil.example/review/travel.example', 'https://travel.example'],
    ['https://evil.example/review/travel.example', 'https://travel.example'],
    ['https://user@www.trustpilot.com/review/travel.example', 'https://travel.example'],
    ['https://www.trustpilot.com@evil.example/review/travel.example', 'https://travel.example'],
    ['https://www.trustpilot.com:443/review/travel.example', 'https://travel.example'],
    ['https://www.trustpilot.com/review/travel.example?rating=5', 'https://travel.example'],
    ['https://www.trustpilot.com/review/travel.example?', 'https://travel.example'],
    ['https://www.trustpilot.com/review/travel.example#reviews', 'https://travel.example'],
    ['https://www.trustpilot.com/review/travel.example#', 'https://travel.example'],
    ['https://www.trustpilot.com/review/other.example', 'https://travel.example'],
    ['https://www.trustpilot.com/review/travel.example.evil.example', 'https://travel.example'],
    ['https://www.trustpilot.com/review/shop.travel.example', 'https://travel.example'],
    ['https://www.trustpilot.com/review/travel.example', 'https://shop.travel.example'],
    ['https://www.trustpilot.com/review/travel%2eexample', 'https://travel.example'],
    ['https://www.trustpilot.com/review/travel.example/extra', 'https://travel.example'],
    ['https://www.trustpilot.com/review/travel.example//', 'https://travel.example'],
    ['https://www.trustpilot.com/review/127.0.0.1', 'https://127.0.0.1'],
]);
