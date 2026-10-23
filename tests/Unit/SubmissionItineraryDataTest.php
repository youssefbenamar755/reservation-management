<?php

use App\Services\SubmissionItineraryData;

function itineraryDataFixture(): array
{
    return [
        'itineraries' => [
            ['segments' => [
                ['id' => '11', 'carrierCode' => 'AT', 'number' => '208', 'duration' => 'PT2H15M',
                    'departure' => ['iataCode' => 'CMN', 'at' => '2026-12-02T09:15:00+01:00', 'terminal' => '1', 'cityName' => 'Casablanca'],
                    'arrival' => ['iataCode' => 'LIS', 'at' => '2026-12-02T10:30:00Z']],
                ['id' => '12', 'carrierCode' => 'TP', 'number' => '440', 'duration' => 'PT2H30M',
                    'departure' => ['iataCode' => 'LIS', 'at' => '2026-12-02T12:00:00'],
                    'arrival' => ['iataCode' => 'ORY', 'at' => '2026-12-02T15:30:00', 'airportName' => 'Paris Orly']],
            ]],
            ['segments' => [
                ['id' => '21', 'carrierCode' => 'AT', 'number' => 'AT751',
                    'departure' => ['iataCode' => 'ORY', 'at' => '2026-12-12T22:10:00'],
                    'arrival' => ['iataCode' => 'CMN', 'at' => '2026-12-13T00:40:00']],
            ]],
        ],
        'dictionaries' => ['carriers' => ['AT' => 'Royal Air Maroc', 'TP' => 'TAP Portugal']],
        'travelerPricings' => [
            ['fareDetailsBySegment' => [
                ['segmentId' => '12', 'cabin' => 'BUSINESS', 'includedCheckedBags' => ['weight' => 23, 'weightUnit' => 'KG']],
                ['segmentId' => '11', 'cabin' => 'ECONOMY', 'includedCheckedBags' => ['quantity' => 0]],
            ]],
        ],
    ];
}

test('itinerary normalization preserves every actual passenger and flight segment without booking anything', function () {
    $data = (new SubmissionItineraryData)->fromPayload(['response' => [
        'names' => ['first_name' => 'Zoë', 'last_name' => 'Ben-Aïssa'],
        'names_5' => ['firstname' => 'Émile', 'lastname' => 'O’Connor'],
        'title' => 'Ms', 'title_2' => 'Dr', 'email' => 'customer@example.test', 'phone' => '+212 600 123 456',
        'flight_departure_json' => json_encode(itineraryDataFixture()),
    ]]);

    expect($data['passengers'])->toBe([
        ['title' => 'Ms', 'first_name' => 'Zoë', 'last_name' => 'Ben-Aïssa', 'name' => 'Zoë Ben-Aïssa'],
        ['title' => 'Dr', 'first_name' => 'Émile', 'last_name' => 'O’Connor', 'name' => 'Émile O’Connor'],
    ])->and($data['contact'])->toBe(['email' => 'customer@example.test', 'phone' => '+212 600 123 456'])
        ->and($data['trips'])->toHaveCount(2)
        ->and($data['trips'][0]['segments'])->toHaveCount(2)
        ->and($data['trips'][0]['label'])->toBe('Flight 1')
        ->and($data['trips'][1]['label'])->toBe('Flight 2');

    $first = $data['trips'][0]['segments'][0];
    expect($first['flight_number'])->toBe('AT208')->and($first['airline'])->toBe('Royal Air Maroc')
        ->and($first['departure'])->toBe(['airport_code' => 'CMN', 'airport_name' => null, 'city' => 'Casablanca', 'date' => '2026-12-02', 'time' => '09:15', 'terminal' => '1', 'gate' => null, 'timezone' => null])
        ->and($first['arrival']['time'])->toBe('10:30')
        ->and($first['duration'])->toBe('2h 15m')->and($first['cabin'])->toBe('ECONOMY')
        ->and($first['baggage'])->toBe('0 checked bags')
        ->and($data['trips'][0]['segments'][1]['cabin'])->toBe('BUSINESS')
        ->and($data['trips'][0]['segments'][1]['baggage'])->toBe('23 KG checked baggage')
        ->and($data['trips'][1]['segments'][0]['flight_number'])->toBe('AT751')
        ->and($data['trips'][1]['segments'][0]['arrival']['date'])->toBe('2026-12-13')
        ->and($data['trips'][1]['segments'][0]['duration'])->toBeNull()
        ->and($data['trips'][1]['segments'][0]['baggage'])->toBeNull();
});

test('submitted carrier name gates and timezones are preserved without converting local flight times', function () {
    $offer = ['itineraries' => [['segments' => [
        ['carrierCode' => 'XY', 'carrierName' => 'Example Étoile Air', 'number' => '123',
            'departure' => ['iataCode' => 'AAA', 'at' => '2026-12-02T23:15:00-08:00', 'gate' => 'B07', 'timezone' => 'America/Los_Angeles'],
            'arrival' => ['iataCode' => 'BBB', 'at' => '2026-12-03T19:30:00+09:00', 'gate' => '009', 'timezone' => 'Asia/Tokyo']],
        ['departure' => ['iataCode' => 'BBB'], 'arrival' => ['iataCode' => 'CCC']],
    ]]]];
    $segments = (new SubmissionItineraryData)->fromPayload(['response' => ['flight_json_data' => $offer]])['trips'][0]['segments'];

    expect($segments[0]['airline'])->toBe('Example Étoile Air')
        ->and($segments[0]['departure']['gate'])->toBe('B07')
        ->and($segments[0]['departure']['timezone'])->toBe('America/Los_Angeles')
        ->and($segments[0]['departure']['date'])->toBe('2026-12-02')
        ->and($segments[0]['departure']['time'])->toBe('23:15')
        ->and($segments[0]['arrival']['gate'])->toBe('009')
        ->and($segments[0]['arrival']['timezone'])->toBe('Asia/Tokyo')
        ->and($segments[0]['arrival']['date'])->toBe('2026-12-03')
        ->and($segments[0]['arrival']['time'])->toBe('19:30')
        ->and($segments[1]['departure']['gate'])->toBeNull()
        ->and($segments[1]['departure']['timezone'])->toBeNull()
        ->and($segments[1]['arrival']['gate'])->toBeNull()
        ->and($segments[1]['arrival']['timezone'])->toBeNull();
});

test('itinerary form response wrappers can contain arrays or JSON without leaking unrelated metadata', function (array $payload) {
    $data = (new SubmissionItineraryData)->fromPayload($payload, 'fallback@example.test');
    expect($data['passengers'][0]['name'])->toBe('Actual Passenger')
        ->and($data['contact']['email'])->toBe('fallback@example.test')
        ->and(array_keys($data))->toBe(['passengers', 'trips', 'contact']);
})->with([
    'response JSON' => [['response' => '{"names":"Actual Passenger"}', 'token' => 'not-exported']],
    'nested data response' => [['data' => ['response' => ['names' => 'Actual Passenger']]]],
    'submission formData' => [['submission' => ['formData' => '{"names":"Actual Passenger"}']]],
    'form_data response' => [['form_data' => ['response' => ['names' => 'Actual Passenger']]]],
    'flat names' => [['names' => 'Actual Passenger']],
    'form field named data is not a wrapper' => [['response' => ['names' => 'Actual Passenger', 'data' => ['unrelated' => 'Preserved elsewhere']]]],
]);

test('passenger title positions survive sparse builder suffixes and empty submitted names', function () {
    $data = (new SubmissionItineraryData)->fromPayload(['response' => [
        'names' => '', 'names_5' => ['first_name' => 'Second'], 'names_12' => ['last_name' => 'Third', 'salutation' => 'Dr'],
        'title' => 'Mr', 'title_2' => 'Mrs', 'title_3' => '',
    ]]);
    expect($data['passengers'])->toBe([
        ['title' => 'Mrs', 'first_name' => 'Second', 'last_name' => null, 'name' => 'Second'],
        ['title' => 'Dr', 'first_name' => null, 'last_name' => 'Third', 'name' => 'Third'],
    ]);
});

test('legacy blank title dropdowns are not compacted and full names are not guessed into first and last', function () {
    $data = (new SubmissionItineraryData)->fromPayload(['response' => [
        'names' => ['full_name' => 'Jean Claude Van Example'],
        'names_9' => '{"firstName":"Second","lastName":"Passenger"}',
        'dropdown' => '', 'dropdown_2' => 'Dr',
    ]]);
    expect($data['passengers'][0])->toBe(['title' => null, 'first_name' => null, 'last_name' => null, 'name' => 'Jean Claude Van Example'])
        ->and($data['passengers'][1]['title'])->toBe('Dr');
});

test('passenger arrays and flat webhook names preserve values without a nine passenger truncation', function () {
    $names = array_map(fn ($number) => ['first_name' => 'Person '.$number], range(1, 12));
    $normalizer = new SubmissionItineraryData;
    expect($normalizer->fromPayload(['response' => ['names' => $names]])['passengers'])->toHaveCount(12)
        ->and($normalizer->fromPayload(['response' => ['first_name' => 'Ada', 'last_name' => 'Example']])['passengers'][0]['name'])->toBe('Ada Example');
});

test('basic flight fields produce only the supplied route dates and an explicit return', function () {
    $data = (new SubmissionItineraryData)->fromPayload(['response' => [
        'flight_from' => 'Casablanca (CMN)', 'flight_to' => 'CDG',
        'departure_date' => '2026-12-02', 'return_date' => '2026-12-12',
    ]]);
    expect($data['passengers'])->toBe([])->and($data['trips'])->toHaveCount(2);
    $outbound = $data['trips'][0]['segments'][0];
    $return = $data['trips'][1]['segments'][0];
    expect($outbound['departure']['airport_code'])->toBe('CMN')
        ->and($outbound['departure']['airport_name'])->toBe('Casablanca (CMN)')
        ->and($outbound['departure']['date'])->toBe('2026-12-02')
        ->and($outbound['departure']['time'])->toBeNull()
        ->and($outbound['arrival']['date'])->toBeNull()
        ->and($return['departure']['airport_code'])->toBe('CDG')
        ->and($return['arrival']['airport_code'])->toBe('CMN')
        ->and($return['departure']['date'])->toBe('2026-12-12')
        ->and($return['arrival']['date'])->toBeNull();
    foreach (['carrier_code', 'airline', 'flight_number', 'duration', 'baggage', 'cabin'] as $missing) {
        expect($outbound[$missing])->toBeNull()->and($return[$missing])->toBeNull();
    }
});

test('legacy flight arrival is a return date and not a fabricated outbound arrival time', function () {
    $data = (new SubmissionItineraryData)->fromPayload(['response' => [
        'flight_from' => 'CMN', 'flight_to' => 'CDG', 'flight_departure' => '2026-12-02', 'flight_arrival' => '2026-12-12',
    ]]);
    expect($data['trips'][0]['segments'][0]['arrival']['date'])->toBeNull()
        ->and($data['trips'][1]['segments'][0]['departure']['date'])->toBe('2026-12-12');
});

test('missing or malformed values do not create passengers airports or flights', function (array $payload) {
    $data = (new SubmissionItineraryData)->fromPayload($payload);
    expect($data)->toBe(['passengers' => [], 'trips' => [], 'contact' => ['email' => null, 'phone' => null]]);
})->with([
    [[]],
    [['response' => '{invalid json']],
    [['response' => ['names' => ['first_name' => [], 'last_name' => false], 'flight_departure_json' => '{broken']]],
    [['response' => ['hotel_name' => 'Not a passenger', 'origin' => 'not a flight', 'destination' => 'not a flight', 'date' => '2026-12-02']]],
]);

test('invalid or ambiguous dates stay empty rather than being normalized to invented calendar values', function (string $date) {
    $data = (new SubmissionItineraryData)->fromPayload(['response' => ['flight_from' => 'CMN', 'flight_to' => 'CDG', 'departure_date' => $date]]);
    expect($data['trips'][0]['segments'][0]['departure']['date'])->toBeNull()
        ->and($data['trips'][0]['segments'][0]['departure']['time'])->toBeNull();
})->with(['2026-02-30', '02/03/2026', 'tomorrow', '2026-12-02T25:61:00']);

test('single offer wrappers retain dictionaries but alternative search offers are not silently selected', function () {
    $offer = itineraryDataFixture();
    unset($offer['dictionaries']);
    $normalizer = new SubmissionItineraryData;
    $data = $normalizer->fromPayload(['response' => ['flight_json_data' => ['data' => [$offer], 'dictionaries' => ['carriers' => ['AT' => 'Actual Airline Name']]]]]);
    expect($data['trips'][0]['segments'][0]['airline'])->toBe('Actual Airline Name');
    $ambiguous = $normalizer->fromPayload(['response' => ['flight_json_data' => ['data' => [$offer, $offer]]]]);
    expect($ambiguous['trips'])->toBe([]);
});

test('fares match segment IDs and differing passenger allowances do not become a shared promise', function () {
    $offer = itineraryDataFixture();
    $offer['travelerPricings'][] = ['fareDetailsBySegment' => [
        ['segmentId' => '11', 'cabin' => 'BUSINESS', 'includedCheckedBags' => ['quantity' => 2]],
        ['segmentId' => '12', 'cabin' => 'BUSINESS', 'includedCheckedBags' => ['weight' => 23, 'weightUnit' => 'KG']],
    ]];
    $segments = (new SubmissionItineraryData)->fromPayload(['response' => ['flight_json_data' => $offer]])['trips'][0]['segments'];
    expect($segments[0]['cabin'])->toBeNull()->and($segments[0]['baggage'])->toBeNull()
        ->and($segments[1]['cabin'])->toBe('BUSINESS')->and($segments[1]['baggage'])->toBe('23 KG checked baggage');
});

test('partly populated segments are retained with unknown facts and duplicate offer fields are ignored', function () {
    $offer = itineraryDataFixture();
    $offer['itineraries'][0]['segments'][1]['arrival'] = [];
    $data = (new SubmissionItineraryData)->fromPayload(['response' => [
        'flight_json_data' => $offer, 'flight_departure_json' => json_encode($offer),
    ]]);
    expect($data['trips'])->toHaveCount(2)
        ->and($data['trips'][0]['segments'])->toHaveCount(2)
        ->and($data['trips'][0]['segments'][1]['departure']['airport_code'])->toBe('LIS')
        ->and($data['trips'][0]['segments'][1]['arrival']['airport_code'])->toBeNull()
        ->and($data['trips'][0]['segments'][1]['arrival']['date'])->toBeNull();
});
