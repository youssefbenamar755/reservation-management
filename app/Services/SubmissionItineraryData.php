<?php

namespace App\Services;

use DateTimeImmutable;
use DateTimeZone;

class SubmissionItineraryData
{
    /** Extract only supplied facts; this method never searches for or books flights. */
    public function fromPayload(array $payload, ?string $email = null): array
    {
        $response = $this->response($payload);
        $trips = [];
        $seenOffers = [];
        $candidates = [$response];
        foreach ($response as $key => $value) {
            if (is_string($key) && preg_match('/^flight.*(?:json|offer)|^selected_?flight$/i', $key)) {
                $candidate = $this->array($value);
                if ($candidate !== null) {
                    $candidates[] = $candidate;
                }
            }
        }
        foreach ($candidates as $candidate) {
            $offer = $this->offer($candidate);
            if ($offer === null) {
                continue;
            }
            $offerTrips = [];
            foreach ($offer['itineraries'] as $itinerary) {
                if (! is_array($itinerary)) {
                    continue;
                }
                if (($trip = $this->trip($itinerary, $offer)) !== null) {
                    $offerTrips[] = $trip;
                }
            }
            // The same selected offer can be saved under more than one form field.
            $key = json_encode($offerTrips, JSON_INVALID_UTF8_SUBSTITUTE);
            if (! isset($seenOffers[$key])) {
                $seenOffers[$key] = true;
                foreach ($offerTrips as $trip) {
                    $trips[] = ['label' => 'Flight '.(count($trips) + 1)] + $trip;
                }
            }
        }

        $contactEmail = $this->field($response, ['email', 'email_address', 'customer_email']);
        if (! filter_var($contactEmail, FILTER_VALIDATE_EMAIL)) {
            $contactEmail = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        }

        return [
            'passengers' => $this->passengers($response),
            'trips' => $trips !== [] ? $trips : $this->basicTrips($response),
            'contact' => [
                'email' => $contactEmail,
                'phone' => $this->field($response, ['phone', 'phone_number', 'telephone', 'mobile', 'mobile_number']),
            ],
        ];
    }

    private function response(array $payload, int $depth = 0): array
    {
        if ($depth >= 6) {
            return [];
        }
        foreach (array_keys($payload) as $key) {
            if (is_string($key) && preg_match('/^(?:names(?:_\d+)?|passengers(?:_\d+)?|first_name|last_name|flight_from|flight_to|flight.*json.*|itineraries)$/i', $key)) {
                return $payload;
            }
        }
        foreach (['response', 'formData', 'form_data', 'submission', 'data'] as $key) {
            $nested = $this->array($payload[$key] ?? null);
            if ($nested !== null && $nested !== [] && ! array_is_list($nested)) {
                return $this->response($nested, $depth + 1);
            }
        }

        return $payload;
    }

    private function passengers(array $response): array
    {
        $slots = [];
        foreach ($response as $key => $value) {
            if (is_string($key) && preg_match('/^(?:names|passengers)(?:_\d+)?$/i', $key)) {
                $decoded = $this->array($value);
                foreach ($decoded !== null && array_is_list($decoded) ? $decoded : [$decoded ?? $value] as $slot) {
                    $slots[] = $slot;
                }
                // An empty submitted group still occupies a title position.
                if ($decoded === []) {
                    $slots[] = null;
                }
            }
        }
        if ($slots === [] && $this->hasName($response)) {
            $slots[] = $response;
        }

        $titles = [];
        foreach ($response as $key => $value) {
            if (is_string($key) && preg_match('/^title(?:_(\d+))?$/i', $key, $match)) {
                $titles[max(0, (int) ($match[1] ?? 1) - 1)] = $this->text($value);
            }
        }
        if (! array_filter($titles, fn ($title) => $title !== null)) {
            $titles = [];
            foreach ($response as $key => $value) {
                if (is_string($key) && preg_match('/^dropdown(?:_\d+)?$/i', $key)) {
                    $titles[] = $this->text($value);
                }
            }
        }

        $passengers = [];
        foreach ($slots as $position => $slot) {
            $slot = $this->array($slot) ?? $slot;
            $first = is_array($slot) ? $this->field($slot, ['first_name', 'firstname', 'firstName']) : null;
            $last = is_array($slot) ? $this->field($slot, ['last_name', 'lastname', 'lastName']) : null;
            $name = is_array($slot) ? $this->field($slot, ['full_name', 'fullName', 'name']) : $this->text($slot);
            if ($first !== null || $last !== null) {
                $name = trim(implode(' ', array_filter([$first, $last], fn ($part) => $part !== null)));
            }
            if ($name !== null) {
                $passengers[] = [
                    'title' => $titles[$position] ?? (is_array($slot) ? $this->field($slot, ['title', 'salutation']) : null),
                    'first_name' => $first, 'last_name' => $last, 'name' => $name,
                ];
            }
        }

        return $passengers;
    }

    private function hasName(array $value): bool
    {
        return $this->field($value, ['first_name', 'firstname', 'firstName', 'last_name', 'lastname', 'lastName', 'full_name']) !== null;
    }

    private function offer(array $value, int $depth = 0): ?array
    {
        if ($depth >= 5) {
            return null;
        }
        if (is_array($value['itineraries'] ?? null)) {
            return $value;
        }
        foreach (['selectedOffer', 'flightOffer', 'flightOffers', 'data'] as $key) {
            $nested = $this->array($value[$key] ?? null);
            if ($nested === null) {
                continue;
            }
            // A search result containing alternatives is not a selected itinerary.
            if (array_is_list($nested)) {
                $nested = count($nested) === 1 && is_array($nested[0]) ? $nested[0] : null;
            }
            if ($nested !== null && ($offer = $this->offer($nested, $depth + 1)) !== null) {
                if (! isset($offer['dictionaries']) && is_array($value['dictionaries'] ?? null)) {
                    $offer['dictionaries'] = $value['dictionaries'];
                }

                return $offer;
            }
        }

        return null;
    }

    private function trip(array $itinerary, array $offer): ?array
    {
        $rawSegments = array_values(is_array($itinerary['segments'] ?? null) ? $itinerary['segments'] : []);
        $segments = [];
        $totalSeconds = 0;
        $canDeriveDuration = true;
        foreach ($rawSegments as $index => $segment) {
            if (! is_array($segment) || ($normalized = $this->segment($segment, $offer)) === null) {
                $canDeriveDuration = false;

                continue;
            }
            $seconds = $this->durationSeconds($segment['duration'] ?? null);
            if ($seconds === null) {
                $canDeriveDuration = false;
            } else {
                $totalSeconds += $seconds;
            }
            if ($index < count($rawSegments) - 1) {
                // Only adjacent submitted segments may form a connection. Never
                // bridge an empty/malformed segment that was omitted from display.
                $next = $rawSegments[$index + 1];
                $layover = is_array($next) ? $this->layover($segment, $next) : null;
                if ($layover === null) {
                    $canDeriveDuration = false;
                } else {
                    $normalized['layover_after'] = $layover['details'];
                    $totalSeconds += $layover['seconds'];
                }
            }
            $segments[] = $normalized;
        }

        return $segments === [] ? null : [
            'duration' => $this->duration($itinerary['duration'] ?? null)
                ?? ($canDeriveDuration ? $this->elapsedDuration($totalSeconds) : null),
            'segments' => $segments,
        ];
    }

    private function segment(array $segment, array $offer): ?array
    {
        $departure = $this->point(is_array($segment['departure'] ?? null) ? $segment['departure'] : []);
        $arrival = $this->point(is_array($segment['arrival'] ?? null) ? $segment['arrival'] : []);
        $carrier = $this->field($segment, ['carrierCode', 'carrier_code']);
        $number = $this->field($segment, ['number', 'flight_number']);
        if ($number !== null && $carrier !== null && ! str_starts_with(strtoupper($number), strtoupper($carrier))) {
            $number = $carrier.$number;
        }
        $fare = $this->commonFare($offer, $segment['id'] ?? null);

        $normalized = [
            'carrier_code' => $carrier,
            'airline' => $this->field($segment, ['airline', 'airlineName', 'carrierName'])
                ?? ($carrier !== null ? $this->text($offer['dictionaries']['carriers'][$carrier] ?? null) : null),
            'flight_number' => $number,
            'operating_carrier' => (is_array($segment['operating'] ?? null)
                ? $this->field($segment['operating'], ['carrierCode', 'carrier_code']) : null)
                ?? $this->field($segment, ['operating_carrier', 'operatingCarrierCode']),
            'airline_reference' => $this->field($segment, ['bookingReference', 'airline_reference', 'booking_reference']),
            'departure' => $departure, 'arrival' => $arrival,
            'duration' => $this->duration($segment['duration'] ?? null),
            'baggage' => $this->baggage($segment['baggageAllowance'] ?? null) ?? $fare['baggage'],
            'cabin' => $this->text($segment['cabin'] ?? null) ?? $fare['cabin'],
            'layover_after' => null,
        ];
        $facts = array_merge($departure, array_values($arrival), array_values(array_diff_key($normalized, ['departure' => true, 'arrival' => true])));

        return array_filter($facts, fn ($value) => $value !== null) !== [] ? $normalized : null;
    }

    private function point(array $point): array
    {
        [$date, $time] = $this->dateTime($point['at'] ?? $point['date'] ?? null);

        return [
            'airport_code' => $this->airportCode($point['iataCode'] ?? $point['airport_code'] ?? null),
            'airport_name' => $this->field($point, ['airportName', 'airport_name', 'name']),
            'city' => $this->field($point, ['cityName', 'city']),
            'date' => $date, 'time' => $time,
            'terminal' => $this->text($point['terminal'] ?? null, true),
            'gate' => $this->text($point['gate'] ?? null, true),
            'timezone' => $this->text($point['timezone'] ?? null),
        ];
    }

    private function layover(array $segment, array $next): ?array
    {
        if (! is_array($segment['arrival'] ?? null) || ! is_array($next['departure'] ?? null)) {
            return null;
        }
        $arrival = $this->point($segment['arrival']);
        $departure = $this->point($next['departure']);
        if ($arrival['airport_code'] !== null && $departure['airport_code'] !== null) {
            $sameAirport = $arrival['airport_code'] === $departure['airport_code'];
        } else {
            $sameAirport = $arrival['airport_name'] !== null && $departure['airport_name'] !== null
                && mb_strtolower($arrival['airport_name']) === mb_strtolower($departure['airport_name']);
        }
        if (! $sameAirport) {
            return null;
        }
        $arrivedAt = $this->instant($segment['arrival']);
        $departsAt = $this->instant($next['departure']);
        if ($arrivedAt === null || $departsAt === null || $departsAt < $arrivedAt) {
            return null;
        }
        $seconds = $departsAt - $arrivedAt;

        return ['seconds' => $seconds, 'details' => [
            'duration' => $this->elapsedDuration($seconds),
            'airport_code' => $arrival['airport_code'] ?? $departure['airport_code'],
            'airport_name' => $arrival['airport_name'] ?? $departure['airport_name'],
        ]];
    }

    private function instant(array $point): ?int
    {
        $value = $this->text($point['at'] ?? $point['date'] ?? null);
        // A date alone or a fractional instant we cannot preserve exactly is
        // insufficient for elapsed-time arithmetic. Displayed source facts stay intact.
        if ($value === null || $this->dateTime($value)[1] === null
            || ! preg_match('/\A(\d{4}-\d{2}-\d{2})[T ](\d{2}:\d{2})(?::(\d{2})(?:\.0+)?)?(Z|[+-]\d{2}:\d{2})?\z/D', $value, $parts)) {
            return null;
        }
        $local = $parts[1].' '.$parts[2].':'.(($parts[3] ?? '') !== '' ? $parts[3] : '00');
        $wallTimestamp = (new DateTimeImmutable($local, new DateTimeZone('UTC')))->getTimestamp();
        $offset = $parts[4] ?? null;
        if ($offset !== null) {
            $seconds = $this->offsetSeconds($offset);

            return $seconds === null ? null : $wallTimestamp - $seconds;
        }
        $timezone = $this->text($point['timezone'] ?? null);
        if ($timezone === null) {
            return null;
        }
        if (($seconds = $this->offsetSeconds($timezone)) !== null) {
            return $wallTimestamp - $seconds;
        }
        // Abbreviations such as CST are ambiguous. Use only a supplied named
        // timezone, and reject both repeated and nonexistent DST local times.
        if ($timezone !== 'UTC' && ! str_contains($timezone, '/')) {
            return null;
        }
        try {
            $zone = new DateTimeZone($timezone);
        } catch (\Exception) {
            return null;
        }
        $transitions = $zone->getTransitions($wallTimestamp - 172800, $wallTimestamp + 172800);
        if ($transitions === false) {
            return null;
        }
        $matches = [];
        foreach (array_unique(array_column($transitions, 'offset')) as $candidateOffset) {
            $candidate = $wallTimestamp - $candidateOffset;
            if ((new DateTimeImmutable('@'.$candidate))->setTimezone($zone)->format('Y-m-d H:i:s') === $local) {
                $matches[] = $candidate;
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function offsetSeconds(string $offset): ?int
    {
        if ($offset === 'Z') {
            return 0;
        }
        if (! preg_match('/\A([+-])(\d{2}):(\d{2})\z/D', $offset, $parts)
            || (int) $parts[2] > 14 || (int) $parts[3] > 59
            || ((int) $parts[2] === 14 && (int) $parts[3] !== 0)) {
            return null;
        }

        return ($parts[1] === '-' ? -1 : 1) * ((int) $parts[2] * 3600 + (int) $parts[3] * 60);
    }

    private function durationSeconds(mixed $value): ?int
    {
        $value = $this->text($value);
        if ($value === null || ! preg_match('/\AP(?:(\d{1,7})D)?(?:T(?:(\d{1,7})H)?(?:(\d{1,7})M)?(?:(\d{1,7})S)?)?\z/D', $value, $parts)
            || ! preg_match('/\d/', $value)) {
            return null;
        }

        return (int) ($parts[1] ?? 0) * 86400 + (int) ($parts[2] ?? 0) * 3600
            + (int) ($parts[3] ?? 0) * 60 + (int) ($parts[4] ?? 0);
    }

    private function elapsedDuration(int $seconds): string
    {
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return trim(($hours ? $hours.'h ' : '').($minutes ? $minutes.'m ' : '')
            .($remainingSeconds ? $remainingSeconds.'s' : ($seconds === 0 ? '0m' : '')));
    }

    private function commonFare(array $offer, mixed $id): array
    {
        $cabins = $bags = [];
        foreach (is_array($offer['travelerPricings'] ?? null) ? $offer['travelerPricings'] : [] as $pricing) {
            $match = null;
            foreach (is_array($pricing) && is_array($pricing['fareDetailsBySegment'] ?? null) ? $pricing['fareDetailsBySegment'] : [] as $fare) {
                if (is_array($fare) && is_scalar($id) && is_scalar($fare['segmentId'] ?? null) && (string) $fare['segmentId'] === (string) $id) {
                    $match = $fare;
                    break;
                }
            }
            $cabins[] = $this->text($match['cabin'] ?? null);
            $bags[] = $this->baggage($match['includedCheckedBags'] ?? null);
        }

        return [
            'cabin' => count(array_unique($cabins, SORT_REGULAR)) === 1 ? $cabins[0] : null,
            'baggage' => count(array_unique($bags, SORT_REGULAR)) === 1 ? $bags[0] : null,
        ];
    }

    private function basicTrips(array $response): array
    {
        $from = $this->basicLocation($this->field($response, ['flight_from', 'flightfrom']));
        $to = $this->basicLocation($this->field($response, ['flight_to', 'flightto']));
        if (! $this->hasLocation($from) || ! $this->hasLocation($to)) {
            return [];
        }
        [$date, $time] = $this->dateTime($this->field($response, ['flight_departure', 'departure_date', 'flight_departure_date']));
        $from['date'] = $date;
        $from['time'] = $time;
        $blank = ['carrier_code' => null, 'airline' => null, 'flight_number' => null, 'operating_carrier' => null,
            'airline_reference' => null, 'duration' => null, 'baggage' => null, 'cabin' => null, 'layover_after' => null];
        $outbound = $blank + ['departure' => $from, 'arrival' => $to];
        $trips = [['label' => 'Outbound', 'duration' => null, 'segments' => [$outbound]]];
        // These are the existing forms' return-date fields; do not infer an arrival time.
        [$returnDate, $returnTime] = $this->dateTime($this->field($response, ['return_date', 'flight_return', 'flight_return_date', 'flight_arrival']));
        if ($returnDate !== null) {
            $returnFrom = $to;
            $returnFrom['date'] = $returnDate;
            $returnFrom['time'] = $returnTime;
            $returnTo = $from;
            $returnTo['date'] = $returnTo['time'] = null;
            $trips[] = ['label' => 'Return', 'duration' => null, 'segments' => [$blank + ['departure' => $returnFrom, 'arrival' => $returnTo]]];
        }

        return $trips;
    }

    private function basicLocation(?string $value): array
    {
        $point = $this->point([]);
        $point['airport_code'] = $this->airportCode($value);
        if ($value !== null && $point['airport_code'] === null) {
            $point['airport_name'] = $value;
            if (preg_match('/\(([A-Za-z]{3})\)\s*$/D', $value, $match)) {
                $point['airport_code'] = strtoupper($match[1]);
            }
        }

        return $point;
    }

    private function hasLocation(array $point): bool
    {
        return $point['airport_code'] !== null || $point['airport_name'] !== null || $point['city'] !== null;
    }

    private function airportCode(mixed $value): ?string
    {
        $value = $this->text($value);

        return $value !== null && preg_match('/^[A-Za-z]{3}$/D', $value) ? strtoupper($value) : null;
    }

    private function dateTime(mixed $value): array
    {
        $value = $this->text($value);
        if ($value === null || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?(?:Z|[+-]\d{2}:\d{2})?)?$/D', $value, $parts)
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (isset($parts[4]) && ((int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) ($parts[6] ?? 0) > 59))) {
            return [null, null];
        }

        // Flight timestamps are local to each airport: never apply the server timezone.
        return [substr($value, 0, 10), isset($parts[4]) ? $parts[4].':'.$parts[5] : null];
    }

    private function duration(mixed $value): ?string
    {
        $value = $this->text($value);
        if ($value !== null && preg_match('/^P(?:(\d+)D)?T(?:(\d+)H)?(?:(\d+)M)?$/D', $value, $parts)) {
            $hours = ((int) ($parts[1] ?? 0) * 24) + (int) ($parts[2] ?? 0);
            $minutes = (int) ($parts[3] ?? 0);

            return trim(($hours ? $hours.'h ' : '').($minutes ? $minutes.'m' : ($hours ? '' : '0m')));
        }

        return $value;
    }

    private function baggage(mixed $value): ?string
    {
        if (is_array($value)) {
            if (isset($value['quantity']) && is_numeric($value['quantity']) && $value['quantity'] >= 0) {
                return $value['quantity'].' checked bag'.((float) $value['quantity'] === 1.0 ? '' : 's');
            }
            if (isset($value['weight'], $value['weightUnit']) && is_numeric($value['weight']) && $value['weight'] >= 0 && $this->text($value['weightUnit']) !== null) {
                return $value['weight'].' '.$this->text($value['weightUnit']).' checked baggage';
            }

            return null;
        }

        return $this->text($value);
    }

    private function field(array $values, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (($value = $this->text($values[$key] ?? null)) !== null) {
                return $value;
            }
        }

        return null;
    }

    private function array(mixed $value): ?array
    {
        if (is_string($value)) {
            $value = json_decode($value, true, 32);
        }

        return is_array($value) ? $value : null;
    }

    private function text(mixed $value, bool $allowNumber = false): ?string
    {
        if (! is_string($value) && ! ($allowNumber && (is_int($value) || is_float($value)))) {
            return null;
        }
        $value = trim(preg_replace('/[\x00-\x1F\x7F]/', ' ', (string) $value));

        return $value !== '' ? $value : null;
    }
}
