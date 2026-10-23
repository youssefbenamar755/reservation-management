<?php

use App\Services\SubmissionItineraryPdf;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;

test('rendering uses packaged carrier artwork and ignores all website or submitted logo URLs', function () {
    Http::preventStrayRequests();
    $captured = [];
    View::composer('pdf.submission-itinerary', function ($view) use (&$captured) {
        $captured = $view->getData();
    });
    $document = [
        'reference' => 'ITN-SAMPLE', 'reference_type' => 'internal',
        'website' => ['name' => 'Hidden Website Brand', 'host' => 'hidden.example', 'logo_data_uri' => 'data:image/png;base64,untrusted'],
        'agency' => ['name' => 'Example Agency', 'address' => '10 Sample Avenue'],
        'passengers' => [['first_name' => 'Alex', 'last_name' => 'Example', 'name' => 'Alex Example']],
        'trips' => [['label' => 'Outbound', 'segments' => [
            ['carrier_code' => 'AF', 'airline' => 'Air France', 'flight_number' => 'AF100', 'logo_url' => 'https://untrusted.example/track', 'departure' => ['airport_code' => 'CDG'], 'arrival' => ['airport_code' => 'CMN']],
            ['carrier_code' => '../AF', 'airline' => 'Unknown Airline', 'departure' => [], 'arrival' => []],
            ['carrier_code' => 'ZZ', 'airline' => 'Unknown Airline', 'departure' => [], 'arrival' => []],
        ]]],
    ];
    $bytes = app(SubmissionItineraryPdf::class)->render($document);
    expect($bytes)->toStartWith('%PDF-');
    if (extension_loaded('gd')) {
        expect($captured['airlineLogoDataUris'])->toHaveKey('AF')->not->toHaveKey('ZZ')->not->toHaveKey('../AF');
        expect($captured['airlineLogoDataUris']['AF'])->toStartWith('data:image/png;base64,');
    } else {
        expect($captured['airlineLogoDataUris'])->toBe([]);
    }
    $html = view('pdf.submission-itinerary', $captured)->render();
    expect($html)->not->toContain('Hidden Website Brand', 'hidden.example', 'untrusted.example', 'data:image/png;base64,untrusted')
        ->toContain('Example Agency', '10 Sample Avenue', 'Alex', 'Example');
    Http::assertNothingSent();
});

test('an external PNR remains operator supplied and internal reference never becomes an airline reference', function () {
    $base = ['reference' => 'ITN-SAMPLE', 'passengers' => [], 'trips' => [], 'airlineLogoDataUris' => [], 'flightIconDataUris' => []];
    $internal = view('pdf.submission-itinerary', ['document' => $base + ['reference_type' => 'internal'], 'airlineLogoDataUris' => [], 'flightIconDataUris' => []])->render();
    expect($internal)->toContain('ITN-SAMPLE')->not->toContain('Booked Itinerary');
    $external = view('pdf.submission-itinerary', ['document' => $base + ['reference_type' => 'external', 'external_pnr' => 'ABC123', 'booking_source' => 'Example Booking System'], 'airlineLogoDataUris' => [], 'flightIconDataUris' => []])->render();
    expect($external)->toContain('ABC123', 'Example Booking System')->not->toContain('Booked Itinerary');
});
