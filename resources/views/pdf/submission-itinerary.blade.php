@php
    $text = static fn ($value) => (is_string($value) || is_numeric($value)) && trim((string) $value) !== ''
        ? trim((string) $value)
        : 'Not provided';
    $present = static fn ($value) => (is_string($value) || is_numeric($value)) && trim((string) $value) !== '';
    $time = static function ($value) use ($text) {
        $value = $text($value);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $value) ? substr($value, 0, 5) : $value;
    };
    $date = static function ($value) use ($text) {
        $value = $text($value);
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return $value;
        }
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed && $parsed->format('Y-m-d') === $value ? $parsed->format('D, d M y') : $value;
    };
    $agency = $document['agency'] ?? [];
    $contact = $document['contact'] ?? [];
    $passengers = $document['passengers'] ?? [];
    $trips = $document['trips'] ?? [];
    $firstTripSegments = $trips[0]['segments'] ?? [];
    $firstDeparture = strtoupper(trim((string) ($firstTripSegments[0]['departure']['airport_code'] ?? '')));
    $firstArrival = strtoupper(trim((string) ($firstTripSegments[array_key_last($firstTripSegments)]['arrival']['airport_code'] ?? '')));
    $airlineLogoDataUris = $airlineLogoDataUris ?? [];
    $flightIconDataUris = $flightIconDataUris ?? [];
    $isExternal = ($document['reference_type'] ?? 'internal') === 'external' && $present($document['external_pnr'] ?? null);
    $reference = $text($document['reference'] ?? null);
    $generatedAt = $text($document['generated_at'] ?? null);
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $generatedAt)) {
        try {
            $generatedAt = (new \DateTimeImmutable($generatedAt))->setTimezone(new \DateTimeZone('UTC'))->format('d M Y, H:i').' UTC';
        } catch (\Exception) {
            // Preserve an unparseable supplied value, escaped in the document.
        }
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Travel Itinerary - {{ $reference }}</title>
    <style>
        @page { size: A4 portrait; margin: 43pt 40pt 42pt; }
        @page :first { margin-top: 21pt; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #111; font-family: Helvetica, Arial, "DejaVu Sans", sans-serif; font-size: 8pt; line-height: 1.18; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { text-align: left; vertical-align: top; word-wrap: break-word; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        h1 { margin: 0 0 14pt; color: #111; font-size: 14pt; line-height: 1.2; font-weight: bold; text-align: center; }
        .document { border: 0.6pt solid #c9c9c9; }
        .reference-strip { padding: 5pt 5pt 6pt; page-break-inside: avoid; }
        .reference-heading { margin-bottom: 3pt; color: #102f6a; font-size: 11pt; font-weight: bold; }
        .reference-line { color: #102f6a; font-size: 9pt; }
        .reference-status { margin-top: 4pt; color: #5e5e5e; font-size: 6.7pt; }
        .agency-band { background: #102e6a; color: #fff; }
        .agency-band td { height: 49pt; padding: 7pt 6pt; border-right: 0.4pt solid #435983; }
        .agency-band td:last-child { border-right: 0; }
        .agency-empty { width: 22%; }
        .agency-consultant, .agency-details { width: 39%; }
        .agency-heading { font-size: 8.4pt; line-height: 1.2; font-weight: bold; }
        .agency-copy { margin-top: 2pt; font-size: 7.1pt; line-height: 1.25; white-space: pre-line; }
        .flight-table { margin: 0; }
        .column-widths th { height: 0; padding: 0; border: 0; font-size: 0; line-height: 0; }
        .trip-heading > th { padding: 7pt 6pt; background: #f2f2f2; border-top: 0.5pt solid #dedede; border-bottom: 0.5pt solid #dedede; color: #656565; }
        .trip-heading-table td { vertical-align: middle; font-size: 8.5pt; line-height: 1.2; font-weight: bold; }
        .trip-title { width: 52%; }
        .trip-duration { width: 48%; text-align: right; }
        .flight-icon { width: 13pt; height: 13pt; margin-right: 9pt; vertical-align: middle; }
        .segment > td { height: 67pt; padding: 7pt 6pt; border-right: 0.5pt solid #e5e5e5; }
        .segment > td:last-child { border-right: 0; }
        .airline-logo { display: block; width: auto; height: auto; max-width: 43pt; max-height: 17pt; margin: 1pt 0 6pt; }
        .carrier-fallback { margin: 0 0 6pt; color: #102f6a; font-size: 11pt; line-height: 1.2; font-weight: bold; }
        .field-label { color: #102f6a; font-size: 7.7pt; font-weight: bold; }
        .detail { font-size: 7.7pt; line-height: 1.18; }
        .baggage-detail { white-space: pre-line; }
        .secondary { margin-top: 3pt; color: #696969; font-size: 7pt; line-height: 1.15; }
        .airline-metadata { margin-top: 3pt; color: #696969; font-size: 7pt; line-height: 1.25; }
        .layover td { padding: 5pt 8pt; border-top: 0.5pt solid #dedede; border-bottom: 0.5pt solid #dedede; color: #102f6a; font-size: 8pt; text-align: center; }
        .layover-dash { color: #7c8eb0; }
        .empty-row td { padding: 10pt 6pt; color: #696969; font-size: 8pt; }
        .passenger-heading th { padding: 6pt; background: #102e6a; color: #fff; font-size: 8.6pt; line-height: 1.2; font-weight: bold; }
        .passenger-item > td { padding: 5pt; }
        .passenger-card { border: 0.6pt solid #dedede; }
        .passenger-caption { padding: 6pt; border-bottom: 0.5pt solid #e5e5e5; color: #696969; font-size: 8.5pt; font-weight: bold; }
        .passenger-fields-wrap { padding: 6pt; }
        .passenger-fields { border: 0.6pt solid #cfcfcf; background: #f7f7f7; }
        .passenger-fields td { height: 28pt; padding: 7pt 6pt; }
        .given-name { width: 30%; }
        .family-name { width: 70%; }
        .full-name td { height: auto; padding-top: 0; }
        .contact-line { padding: 5pt 6pt; border-top: 0.5pt solid #e5e5e5; color: #696969; font-size: 7pt; line-height: 1.3; page-break-inside: avoid; word-wrap: break-word; }
        .contact-separator { color: #aaa; }
        .document-notes { padding: 4pt 6pt 6pt; color: #696969; font-size: 6.4pt; line-height: 1.3; page-break-inside: avoid; }
        .page-footer { position: fixed; left: 0; right: 0; bottom: -24pt; height: 15pt; padding-top: 4pt; border-top: 0.5pt solid #dedede; color: #696969; font-size: 6.5pt; }
        .footer-reference { width: 78%; word-wrap: break-word; }
    </style>
</head>
<body>
    <div class="page-footer"><div class="footer-reference">Itinerary reference: {{ $reference }}</div></div>
    <h1>Travel Itinerary</h1>

    <div class="document">
        <div class="reference-strip">
            <div class="reference-heading">Travel Itinerary</div>
            <div class="reference-line">
                {{ $isExternal ? 'Booking PNR (provided by operator)' : 'Itinerary reference' }}:
                {{ $isExternal ? $document['external_pnr'] : $reference }}
            </div>
            <div class="reference-status">
                @if ($isExternal)
                    Booking PNR supplied by the operator. Reservation status is not verified by this document.
                    @if ($present($document['booking_source'] ?? null))
                        <br>Booking source: {{ $document['booking_source'] }}
                    @endif
                @else
                    Travel plan only. This reference does not confirm an airline reservation.
                @endif
            </div>
        </div>

        <table class="agency-band">
            <tbody><tr>
                <td class="agency-empty"></td>
                <td class="agency-consultant">
                    <div class="agency-heading">Consultant</div>
                    @if ($present($agency['consultant_name'] ?? null))
                        <div class="agency-heading">{{ $agency['consultant_name'] }}</div>
                    @endif
                </td>
                <td class="agency-details">
                    <div class="agency-heading">{{ $present($agency['name'] ?? null) ? $agency['name'] : 'Agency' }}</div>
                    @if ($present($agency['address'] ?? null))
                        <div class="agency-copy">{{ $agency['address'] }}</div>
                    @endif
                </td>
            </tr></tbody>
        </table>

        @forelse ($trips as $trip)
            @php
                $tripLabel = $text($trip['label'] ?? null);
                $tripSegments = $trip['segments'] ?? [];
                $tripDeparture = strtoupper(trim((string) ($tripSegments[0]['departure']['airport_code'] ?? '')));
                $tripArrival = strtoupper(trim((string) ($tripSegments[array_key_last($tripSegments)]['arrival']['airport_code'] ?? '')));
                $reversesFirstTrip = $firstDeparture !== '' && $firstArrival !== '' && $firstDeparture !== $firstArrival
                    && $tripDeparture === $firstArrival && $tripArrival === $firstDeparture;
                $isReturn = ! $loop->first && (preg_match('/\b(return|retour|inbound)\b/i', $tripLabel) || $reversesFirstTrip);
                $direction = $isReturn ? 'return' : 'outbound';
                $tripHeading = $loop->first ? 'Departure Flight Details' : ($isReturn ? 'Return Flight Details' : 'Flight '.$loop->iteration.' Details');
            @endphp
            <table class="flight-table">
                <thead>
                    {{-- Dompdf reads fixed table widths from the first row rather than colgroup. --}}
                    <tr class="column-widths" aria-hidden="true"><th style="width: 19%;"></th><th style="width: 25.5%;"></th><th style="width: 25.5%;"></th><th style="width: 16%;"></th><th style="width: 14%;"></th></tr>
                    <tr class="trip-heading"><th colspan="5">
                        <table class="trip-heading-table"><tbody><tr>
                            <td class="trip-title">
                                @if (! empty($flightIconDataUris[$direction]))
                                    <img class="flight-icon" src="{{ $flightIconDataUris[$direction] }}" alt="">
                                @endif
                                {{ $tripHeading }}
                            </td>
                            <td class="trip-duration">
                                @if ($present($trip['duration'] ?? null))
                                    Total Flight Duration: {{ $trip['duration'] }}
                                @endif
                            </td>
                        </tr></tbody></table>
                    </th></tr>
                </thead>
                <tbody>
                    @forelse (($trip['segments'] ?? []) as $segment)
                        @php
                            $departure = $segment['departure'] ?? [];
                            $arrival = $segment['arrival'] ?? [];
                            $carrierCode = strtoupper(trim((string) ($segment['carrier_code'] ?? '')));
                            $airlineLogo = $airlineLogoDataUris[$carrierCode] ?? null;
                            $layover = $segment['layover_after'] ?? null;
                            $layoverLocation = (is_array($layover) && $present($layover['airport_name'] ?? null) ? ', '.$layover['airport_name'] : '')
                                .(is_array($layover) && $present($layover['airport_code'] ?? null) ? ' ('.$layover['airport_code'].')' : '');
                        @endphp
                        <tr class="segment">
                            <td>
                                @if ($airlineLogo)
                                    <img class="airline-logo" src="{{ $airlineLogo }}" alt="{{ $text($segment['airline'] ?? $carrierCode) }}">
                                @elseif ($present($carrierCode))
                                    <div class="carrier-fallback">{{ $carrierCode }}</div>
                                @endif
                                <div class="detail">{{ $text($segment['airline'] ?? null) }}</div>
                                <div class="detail">{{ $text($segment['flight_number'] ?? null) }}</div>
                                @if ($present($segment['airline_reference'] ?? null))
                                    <div class="airline-metadata">Airline Ref. {{ $segment['airline_reference'] }}</div>
                                @endif
                                <div class="airline-metadata">Cabin Class: {{ $text($segment['cabin'] ?? null) }}</div>
                                @if ($present($segment['operating_carrier'] ?? null))
                                    <div class="airline-metadata">Operated By {{ $segment['operating_carrier'] }}</div>
                                @endif
                            </td>
                            @foreach ([$departure, $arrival] as $airport)
                                @php
                                    $airportPlace = $present($airport['airport_name'] ?? null)
                                        ? $airport['airport_name']
                                        : $text($airport['city'] ?? null);
                                @endphp
                                <td>
                                    <div class="field-label">{{ $loop->first ? 'Departing' : 'Arrival' }}</div>
                                    <div class="detail">
                                        {{ $airportPlace }}
                                        @if ($present($airport['airport_code'] ?? null))
                                            ({{ $airport['airport_code'] }})
                                        @endif
                                    </div>
                                    <div class="detail">
                                        {{ $date($airport['date'] ?? null) }}, {{ $time($airport['time'] ?? null) }}
                                        @if ($present($airport['time'] ?? null))
                                            hrs
                                        @endif
                                    </div>
                                    @if ($present($airport['city'] ?? null) && $airport['city'] !== $airportPlace)
                                        <div class="secondary">{{ $airport['city'] }}</div>
                                    @endif
                                    @if ($present($airport['terminal'] ?? null))
                                        <div class="secondary">Terminal - {{ $airport['terminal'] }}</div>
                                    @endif
                                    @if ($present($airport['gate'] ?? null))
                                        <div class="secondary">Gate - {{ $airport['gate'] }}</div>
                                    @endif
                                    @if ($present($airport['timezone'] ?? null))
                                        <div class="secondary">Timezone: {{ $airport['timezone'] }}</div>
                                    @endif
                                </td>
                            @endforeach
                            <td><div class="field-label">Flight Duration</div><div class="detail">{{ $text($segment['duration'] ?? null) }}</div></td>
                            <td><div class="field-label">Baggage</div><div class="detail baggage-detail">{{ $text($segment['baggage'] ?? null) }}</div></td>
                        </tr>
                        @if (is_array($layover) && $present($layover['duration'] ?? null))
                            <tr class="layover"><td colspan="5">
                                <span class="layover-dash">-----------</span>
                                Layover: {{ $layover['duration'].$layoverLocation }}
                                <span class="layover-dash">-----------</span>
                            </td></tr>
                        @endif
                    @empty
                        <tr class="empty-row"><td colspan="5">Flight details not provided.</td></tr>
                    @endforelse
                </tbody>
            </table>
        @empty
            <table class="flight-table"><tbody><tr class="empty-row"><td>Flight details not provided.</td></tr></tbody></table>
        @endforelse

        <table class="passenger-table">
            <thead><tr class="passenger-heading"><th>Passenger(s) Details</th></tr></thead>
            <tbody>
                @forelse ($passengers as $passenger)
                    @php
                        $givenName = $present($passenger['first_name'] ?? null)
                            ? trim(($passenger['title'] ?? '').' '.$passenger['first_name'])
                            : null;
                        $parsedName = trim(($passenger['first_name'] ?? '').' '.($passenger['last_name'] ?? ''));
                        $showFullName = $present($passenger['name'] ?? null)
                            && ! in_array(trim($passenger['name']), [$parsedName, trim(($passenger['title'] ?? '').' '.$parsedName)], true);
                    @endphp
                    <tr class="passenger-item"><td>
                        <div class="passenger-card">
                            <div class="passenger-caption">Passenger {{ $loop->iteration }}</div>
                            <div class="passenger-fields-wrap">
                                <table class="passenger-fields"><tbody>
                                    <tr>
                                        <td class="given-name">
                                            <div class="field-label">Given Name</div><div class="detail">{{ $text($givenName) }}</div>
                                            @if (! $present($passenger['first_name'] ?? null) && $present($passenger['title'] ?? null))
                                                <div class="secondary">Title: {{ $passenger['title'] }}</div>
                                            @endif
                                        </td>
                                        <td class="family-name"><div class="field-label">Family Name</div><div class="detail">{{ $text($passenger['last_name'] ?? null) }}</div></td>
                                    </tr>
                                    @if ($showFullName)
                                        <tr class="full-name"><td colspan="2"><div class="field-label">Full Name</div><div class="detail">{{ $passenger['name'] }}</div></td></tr>
                                    @endif
                                </tbody></table>
                            </div>
                        </div>
                    </td></tr>
                @empty
                    <tr class="empty-row"><td>Passenger details not provided.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="contact-line">
            Contact: {{ $text($contact['email'] ?? null) }}
            <span class="contact-separator"> &nbsp; | &nbsp; </span>
            Phone: {{ $text($contact['phone'] ?? null) }}
        </div>
        <div class="document-notes">
            Dates and times are shown as submitted, without timezone conversion.<br>
            Generated: {{ $generatedAt }}
        </div>
    </div>
</body>
</html>
