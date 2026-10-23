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

        return $parsed && $parsed->format('Y-m-d') === $value ? $parsed->format('d M Y') : $value;
    };
    $website = $document['website'] ?? [];
    $contact = $document['contact'] ?? [];
    $passengers = $document['passengers'] ?? [];
    $trips = $document['trips'] ?? [];
    $isExternal = ($document['reference_type'] ?? 'internal') === 'external' && $present($document['external_pnr'] ?? null);
    $reference = $text($document['reference'] ?? null);
    $generatedAt = $text($document['generated_at'] ?? null);
    if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $generatedAt)) {
        try {
            $generatedAt = (new \DateTimeImmutable($generatedAt))->setTimezone(new \DateTimeZone('UTC'))->format('d M Y, H:i').' UTC';
        } catch (\Exception) {
            // Keep an unparseable supplied value visible, still escaped below.
        }
    }
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Travel Itinerary - {{ $reference }}</title>
    <style>
        @page { size: A4 portrait; margin: 31pt 31pt 48pt; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #203047; font-family: "DejaVu Sans", sans-serif; font-size: 8pt; line-height: 1.3; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { text-align: left; vertical-align: top; word-wrap: break-word; }
        thead { display: table-header-group; }
        tr { page-break-inside: avoid; }
        h1 { margin: 0 0 3pt; color: #152b49; font-size: 18pt; font-weight: normal; text-align: center; letter-spacing: 0.1pt; }
        .subtitle { margin: 0 0 12pt; color: #718096; font-size: 7pt; text-align: center; }
        .brand-header { margin-bottom: 8pt; background: #152b49; color: #fff; }
        .brand-header td { padding: 8pt 12pt; vertical-align: middle; }
        .brand-cell { width: 54%; }
        .reference-cell { width: 46%; border-left: 1pt solid #314661; }
        .logo-wrap { margin: 0 0 3pt; }
        .logo-tile { display: inline-block; padding: 3pt 5pt; background: #fff; }
        .logo { display: block; max-width: 130pt; max-height: 24pt; width: auto; height: auto; }
        .brand-name { font-size: 9.5pt; font-weight: bold; line-height: 1.25; }
        .brand-host { margin-top: 2pt; color: #d2dce8; font-size: 6.8pt; }
        .reference-label { margin-bottom: 4pt; color: #d2dce8; font-size: 7pt; }
        .reference-value { font-size: 14pt; font-weight: bold; line-height: 1.25; word-wrap: break-word; }
        .booking-source { margin-top: 5pt; font-size: 7pt; color: #d2dce8; }
        .notice { margin: 0 0 11pt; padding: 6pt 8pt; border: 0.6pt solid #dce2ea; color: #546478; font-size: 7.2pt; line-height: 1.4; }
        .flight-table { margin-bottom: 11pt; }
        .column-widths th { padding: 0; border: 0; height: 0; font-size: 0; line-height: 0; }
        .trip-heading th { padding: 6pt 8pt; background: #edf0f4; border: 0.6pt solid #dce2ea; color: #152b49; font-size: 9pt; font-weight: bold; }
        .column-heading th { padding: 4pt 7pt; background: #f7f8fa; border-bottom: 0.6pt solid #dce2ea; color: #617086; font-size: 6.8pt; font-weight: normal; }
        .segment td { padding: 7pt; border-bottom: 0.6pt solid #dce2ea; }
        .airline-name { margin: 0 0 4pt; font-size: 8.2pt; font-weight: bold; color: #203047; }
        .flight-number { font-size: 8pt; }
        .secondary { margin-top: 3pt; color: #68778a; font-size: 6.9pt; line-height: 1.3; }
        .airport-code { margin: 0 0 2pt; color: #152b49; font-size: 12pt; font-weight: bold; line-height: 1.2; }
        .airport-code.missing { font-size: 8pt; font-weight: normal; line-height: 1.45; }
        .airport-place { margin: 0 0 3pt; color: #546478; font-size: 7.1pt; }
        .flight-time { color: #203047; font-size: 9.5pt; font-weight: bold; line-height: 1.3; }
        .flight-time.missing { font-size: 8pt; font-weight: normal; }
        .flight-date { margin-top: 1pt; color: #546478; font-size: 7.4pt; }
        .detail-value { font-size: 8pt; font-weight: bold; }
        .empty-row td { padding: 13pt 10pt; border-bottom: 0.6pt solid #dce2ea; color: #68778a; }
        .passenger-table { margin-bottom: 11pt; }
        .passenger-heading th { padding: 6pt 8pt; background: #152b49; color: #fff; font-size: 9pt; font-weight: bold; }
        .passenger-number { width: 9%; color: #7b8796; }
        .passenger-row td { padding: 5pt 8pt; border-bottom: 0.6pt solid #dce2ea; }
        .passenger-name { font-size: 8.5pt; font-weight: bold; color: #203047; }
        .passenger-title { width: 17%; color: #68778a; font-size: 7.2pt; }
        .contact-block { padding: 8pt; background: #f5f7f9; page-break-inside: avoid; }
        .contact-heading { margin: 0 0 5pt; color: #152b49; font-size: 8pt; font-weight: bold; }
        .contact-table td { padding: 0 12pt 0 0; color: #546478; font-size: 7.2pt; }
        .contact-label { color: #7b8796; }
        .document-note { margin: 8pt 0 0; color: #718096; font-size: 6.8pt; }
        .page-footer { position: fixed; left: 0; right: 0; bottom: -26pt; height: 16pt; border-top: 0.6pt solid #dce2ea; padding-top: 5pt; color: #68778a; font-size: 6.7pt; }
        .footer-reference { width: 78%; word-wrap: break-word; }
    </style>
</head>
<body>
    <div class="page-footer">
        <div class="footer-reference">Itinerary reference: {{ $reference }}</div>
    </div>

    <h1>Travel Itinerary</h1>
    <div class="subtitle">Prepared from submitted travel details</div>

    <table class="brand-header">
        <tbody>
            <tr>
                <td class="brand-cell">
                    @if ($logoDataUri)
                        <div class="logo-wrap"><div class="logo-tile"><img class="logo" src="{{ $logoDataUri }}" alt="{{ $text($website['name'] ?? null) }}"></div></div>
                    @endif
                    <div class="brand-name">{{ $text($website['name'] ?? null) }}</div>
                    @if ($present($website['host'] ?? null))
                        <div class="brand-host">{{ $website['host'] }}</div>
                    @endif
                </td>
                <td class="reference-cell">
                    <div class="reference-label">{{ $isExternal ? 'Booking PNR (provided by operator)' : 'Itinerary reference' }}</div>
                    <div class="reference-value">{{ $isExternal ? $document['external_pnr'] : $reference }}</div>
                    @if ($isExternal)
                        <div class="booking-source">Booking source: {{ $text($document['booking_source'] ?? null) }}</div>
                    @endif
                </td>
            </tr>
        </tbody>
    </table>

    <div class="notice">
        @if ($isExternal)
            Booking PNR supplied by the operator. Reservation status is not verified by this document.
        @else
            Travel plan only. This reference does not confirm an airline reservation.
        @endif
    </div>

    @forelse ($trips as $trip)
        <table class="flight-table">
            <thead>
                {{-- Dompdf derives fixed table widths from the first row, not colgroup. --}}
                <tr class="column-widths" aria-hidden="true"><th style="width: 20%;"></th><th style="width: 26%;"></th><th style="width: 26%;"></th><th style="width: 12%;"></th><th style="width: 16%;"></th></tr>
                <tr class="trip-heading"><th colspan="5">{{ $text($trip['label'] ?? null) }}</th></tr>
                <tr class="column-heading">
                    <th>Airline / flight</th>
                    <th>Departure</th>
                    <th>Arrival</th>
                    <th>Duration</th>
                    <th>Baggage</th>
                </tr>
            </thead>
            <tbody>
                @forelse (($trip['segments'] ?? []) as $segment)
                    @php
                        $departure = $segment['departure'] ?? [];
                        $arrival = $segment['arrival'] ?? [];
                    @endphp
                    <tr class="segment">
                        <td>
                            <div class="airline-name">{{ $text($segment['airline'] ?? null) }}</div>
                            <div class="flight-number">{{ $text($segment['flight_number'] ?? null) }}</div>
                            @if ($present($segment['carrier_code'] ?? null))
                                <div class="secondary">Carrier: {{ $segment['carrier_code'] }}</div>
                            @endif
                            <div class="secondary">Cabin: {{ $text($segment['cabin'] ?? null) }}</div>
                        </td>
                        @foreach ([$departure, $arrival] as $airport)
                            <td>
                                <div class="airport-code {{ $present($airport['airport_code'] ?? null) ? '' : 'missing' }}">{{ $text($airport['airport_code'] ?? null) }}</div>
                                <div class="airport-place">
                                    {{ $text($airport['city'] ?? null) }}
                                    @if ($present($airport['airport_name'] ?? null))
                                        <br>{{ $airport['airport_name'] }}
                                    @endif
                                </div>
                                <div class="flight-time {{ $present($airport['time'] ?? null) ? '' : 'missing' }}">{{ $time($airport['time'] ?? null) }}</div>
                                <div class="flight-date">{{ $date($airport['date'] ?? null) }}</div>
                                <div class="secondary">Terminal: {{ $text($airport['terminal'] ?? null) }}</div>
                                @if ($present($airport['gate'] ?? null))
                                    <div class="secondary">Gate: {{ $airport['gate'] }}</div>
                                @endif
                                @if ($present($airport['timezone'] ?? null))
                                    <div class="secondary">Timezone: {{ $airport['timezone'] }}</div>
                                @endif
                            </td>
                        @endforeach
                        <td><div class="detail-value">{{ $text($segment['duration'] ?? null) }}</div></td>
                        <td><div class="detail-value">{{ $text($segment['baggage'] ?? null) }}</div></td>
                    </tr>
                @empty
                    <tr class="empty-row"><td colspan="5">Flight details not provided.</td></tr>
                @endforelse
            </tbody>
        </table>
    @empty
        <table class="flight-table">
            <thead><tr class="trip-heading"><th>Flight details</th></tr></thead>
            <tbody><tr class="empty-row"><td>Flight details not provided.</td></tr></tbody>
        </table>
    @endforelse

    <table class="passenger-table">
        <thead>
            <tr class="column-widths" aria-hidden="true"><th style="width: 9%;"></th><th style="width: 74%;"></th><th style="width: 17%;"></th></tr>
            <tr class="passenger-heading"><th colspan="3">Passengers</th></tr>
        </thead>
        <tbody>
            @forelse ($passengers as $passenger)
                @php
                    $passengerName = $present($passenger['name'] ?? null)
                        ? $passenger['name']
                        : trim(($passenger['first_name'] ?? '').' '.($passenger['last_name'] ?? ''));
                @endphp
                <tr class="passenger-row">
                    <td class="passenger-number">{{ str_pad((string) $loop->iteration, 2, '0', STR_PAD_LEFT) }}</td>
                    <td class="passenger-name">{{ $text($passengerName) }}</td>
                    <td class="passenger-title">{{ $present($passenger['title'] ?? null) ? $passenger['title'] : '' }}</td>
                </tr>
            @empty
                <tr class="empty-row"><td colspan="3">Passenger details not provided.</td></tr>
            @endforelse
        </tbody>
    </table>

    <div class="contact-block">
        <div class="contact-heading">Contact details</div>
        <table class="contact-table">
            <tbody><tr>
                <td><span class="contact-label">Email</span><br>{{ $text($contact['email'] ?? null) }}</td>
                <td><span class="contact-label">Phone</span><br>{{ $text($contact['phone'] ?? null) }}</td>
            </tr></tbody>
        </table>
    </div>
    <div class="document-note">
        Dates and times are shown as submitted, without timezone conversion.<br>
        Generated: {{ $generatedAt }}
    </div>
</body>
</html>
