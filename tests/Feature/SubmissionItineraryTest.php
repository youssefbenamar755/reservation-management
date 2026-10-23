<?php

use App\Models\FfSubmission;
use App\Models\SubmissionItinerary;
use App\Models\User;
use App\Models\Website;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
    config(['inertia.ssr.enabled' => false]);
    Http::preventStrayRequests();
    $this->owner = User::factory()->create();
    $this->site = Website::create(['user_id' => $this->owner->id, 'name' => 'Example Travel', 'slug' => 'itinerary-example', 'base_url' => 'https://example.test']);
    $this->entry = FfSubmission::create(['website_id' => $this->site->id, 'entry_id' => 1776, 'form_id' => 4,
        'email' => 'traveller@example.test', 'payment_status' => 'paid',
        'payload' => ['response' => ['names' => ['first_name' => 'Zoé', 'last_name' => 'Martin'], 'title' => 'Mme',
            'flight_from' => 'CMN', 'flight_to' => 'CDG', 'flight_departure' => '2026-11-01T10:15:00', 'return_date' => '2026-11-06']],
    ]);
    $this->actingAs($this->owner);
});

test('internal itinerary snapshots submitted facts and retains its reference when regenerated', function () {
    $before = $this->entry->fresh()->getAttributes();
    $this->postJson(route('submissions.itinerary.store', $this->entry), ['reference_type' => 'internal'])
        ->assertOk()->assertJsonPath('itinerary.reference_type', 'internal')->assertJsonPath('itinerary.external_pnr', null);
    $document = SubmissionItinerary::firstOrFail();
    expect($document->reference)->toStartWith('ITN-');
    expect($document->snapshot['passengers'][0]['first_name'])->toBe('Zoé');
    expect($document->snapshot['trips'][0]['segments'][0]['departure']['airport_code'])->toBe('CMN');
    expect($document->snapshot['trips'][0]['segments'][0]['baggage'])->toBeNull();
    expect($this->entry->fresh()->getAttributes())->toBe($before);
    $reference = $document->reference;

    $this->postJson(route('submissions.itinerary.store', $this->entry), ['reference_type' => 'internal', 'external_pnr' => 'IGNORE'])
        ->assertOk()->assertJsonPath('itinerary.reference', $reference)->assertJsonPath('itinerary.external_pnr', null);
    expect(SubmissionItinerary::count())->toBe(1);
    Http::assertNothingSent();
});

test('external PNR requires a source and is never fabricated or treated as a generated reference', function () {
    $this->postJson(route('submissions.itinerary.store', $this->entry), ['reference_type' => 'external'])
        ->assertUnprocessable()->assertJsonValidationErrors(['external_pnr', 'booking_source']);
    $this->postJson(route('submissions.itinerary.store', $this->entry), ['reference_type' => 'external', 'external_pnr' => '<script>', 'booking_source' => "API\nInjected"])
        ->assertUnprocessable()->assertJsonValidationErrors(['external_pnr', 'booking_source']);
    $this->postJson(route('submissions.itinerary.store', $this->entry), ['reference_type' => 'external', 'external_pnr' => 'abc123', 'booking_source' => 'Operator booking system'])
        ->assertOk()->assertJsonPath('itinerary.external_pnr', 'ABC123')->assertJsonPath('itinerary.booking_source', 'Operator booking system');
    expect(SubmissionItinerary::first()->reference)->toStartWith('ITN-');
    $reference = SubmissionItinerary::first()->reference;
    $this->postJson(route('submissions.itinerary.store', $this->entry), ['reference_type' => 'internal'])
        ->assertOk()->assertJsonPath('itinerary.reference', $reference)
        ->assertJsonPath('itinerary.external_pnr', null)->assertJsonPath('itinerary.booking_source', null);
    Http::assertNothingSent();
});

test('guests are redirected and administrators can prepare a website itinerary', function () {
    \Illuminate\Support\Facades\Auth::logout();
    $this->get(route('submissions.itinerary.download', $this->entry))->assertRedirect(route('login'));
    $this->postJson(route('submissions.itinerary.store', $this->entry), ['reference_type' => 'internal'])->assertUnauthorized();
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $this->postJson(route('submissions.itinerary.store', $this->entry), ['reference_type' => 'internal'])->assertOk();
    Http::assertNothingSent();
});

test('legacy PDF is downloaded as stored without changing or recreating the booking', function () {
    \Illuminate\Support\Facades\Storage::fake('public');
    $bytes = '%PDF-1.4 legacy fixture';
    \Illuminate\Support\Facades\Storage::disk('public')->put('dummy-tickets/legacy.pdf', $bytes);
    $this->entry->update(['pnr' => 'LEGACY', 'pnr_pdf_path' => 'dummy-tickets/legacy.pdf']);
    $before = $this->entry->fresh()->getAttributes();
    $response = $this->get(route('submissions.download-pdf', $this->entry))->assertOk();
    expect($response->streamedContent())->toBe($bytes);
    expect($this->entry->fresh()->getAttributes())->toBe($before);
    expect(SubmissionItinerary::count())->toBe(0);
    $this->entry->update(['pnr_pdf_path' => 'dummy-tickets/../../secret.pdf']);
    $this->get(route('submissions.download-pdf', $this->entry))->assertNotFound();
    Http::assertNothingSent();
});

test('generation and private PDF download enforce website ownership', function () {
    $this->postJson(route('submissions.itinerary.store', $this->entry), ['reference_type' => 'internal'])->assertOk();
    $this->actingAs(User::factory()->create());
    $this->postJson(route('submissions.itinerary.store', $this->entry), ['reference_type' => 'internal'])->assertForbidden();
    $this->get(route('submissions.itinerary.download', $this->entry))->assertForbidden();
    $this->get(route('submissions.download-pdf', $this->entry))->assertForbidden();
    $this->postJson(route('submissions.generate-pnr', $this->entry))->assertForbidden();
    Http::assertNothingSent();
});

test('PDF uses its saved snapshot without rebooking or changing the submission', function () {
    $this->post(route('submissions.itinerary.store', $this->entry), ['reference_type' => 'internal'])
        ->assertRedirect(route('submissions.entry-details', $this->entry));
    $saved = SubmissionItinerary::firstOrFail()->snapshot;
    $this->entry->update(['payload' => ['response' => ['names' => ['first_name' => 'Different', 'last_name' => 'Customer']]]]);
    $response = $this->get(route('submissions.itinerary.download', $this->entry))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($response->getContent())->toStartWith('%PDF-');
    expect($response->headers->get('Cache-Control'))->toContain('private')->toContain('no-store');
    expect(SubmissionItinerary::first()->snapshot)->toBe($saved);
    Http::assertNothingSent();
});

test('incomplete submissions cannot create fabricated itineraries and retired APIs do not call Amadeus', function () {
    $this->entry->update(['payload' => []]);
    $this->postJson(route('submissions.itinerary.store', $this->entry), ['reference_type' => 'internal'])->assertUnprocessable()->assertJsonValidationErrors('itinerary');
    expect(SubmissionItinerary::count())->toBe(0);
    $this->postJson(route('submissions.generate-pnr', $this->entry))->assertGone();
    $this->postJson(route('submissions.generate-amadeus-code', $this->entry))->assertGone();
    $this->get(route('submissions.download-pdf', $this->entry))->assertNotFound();
    Http::assertNothingSent();
});

test('details expose the document controls and authenticated URL without its private snapshot', function () {
    $this->postJson(route('submissions.itinerary.store', $this->entry), ['reference_type' => 'internal'])->assertOk();
    $document = SubmissionItinerary::firstOrFail();
    $this->get(route('submissions.entry-details', $this->entry))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('itinerary.reference', $document->reference)->where('itineraryCanGenerate', true)
        ->where('itinerary.download_url', route('submissions.itinerary.download', $this->entry, false))->missing('itinerary.snapshot'));
});

test('agency details are scoped to the website and saved without website logos', function () {
    \App\Models\Setting::set('itinerary_agency:'.$this->site->id, ['name' => 'Example Agency', 'address' => '10 Sample Avenue', 'consultant_name' => 'Example Consultant']);
    \App\Models\Setting::set('itinerary_agency:999999', ['name' => 'Unrelated Agency']);
    $this->postJson(route('submissions.itinerary.store', $this->entry), ['reference_type' => 'internal'])->assertOk();
    $snapshot = SubmissionItinerary::firstOrFail()->snapshot;
    expect($snapshot['agency'])->toBe(['consultant_name' => 'Example Consultant', 'name' => 'Example Agency', 'address' => '10 Sample Avenue']);
    expect($snapshot['website'])->not->toHaveKey('logo_data_uri');
    \App\Models\Setting::set('itinerary_agency:'.$this->site->id, ['name' => 'Changed Agency']);
    $this->get(route('submissions.itinerary.download', $this->entry))->assertOk();
    expect(SubmissionItinerary::firstOrFail()->snapshot['agency'])->toBe($snapshot['agency']);
    Http::assertNothingSent();
});

test('metadata-only flights and oversized text are rejected rather than invented or truncated', function (array $response) {
    $this->entry->update(['payload' => ['response' => $response]]);
    $this->postJson(route('submissions.itinerary.store', $this->entry), ['reference_type' => 'internal'])
        ->assertUnprocessable()->assertJsonValidationErrors('itinerary');
    expect(SubmissionItinerary::count())->toBe(0);
    Http::assertNothingSent();
})->with([
    [['names' => ['first_name' => 'Sample', 'last_name' => 'Traveller'], 'flight_json_data' => ['itineraries' => [['segments' => [['carrierCode' => 'AT']]]]]]],
    [['names' => ['first_name' => str_repeat('A', 501), 'last_name' => 'Traveller'], 'flight_from' => 'CMN', 'flight_to' => 'CDG']],
]);
