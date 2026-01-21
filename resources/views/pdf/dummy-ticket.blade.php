<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Booked Itinerary - {{ strtoupper($pnr ?? 'N/A') }}</title>
  <style>
    * {
      margin: 0;
      padding: 0;
      box-sizing: border-box;
    }

    body {
      font-family: Helvetica, Arial, sans-serif;
      background: #ffffff;
      padding: 20px;
      margin: 0;
    }

    @media print {
      body {
        background: white;
        padding: 0;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
      }
      .print-btn { display: none !important; }
      .ticket-container { box-shadow: none !important; }
    }

    .print-btn {
      background: #1a365d;
      color: white;
      padding: 10px 20px;
      border: none;
      border-radius: 4px;
      cursor: pointer;
      margin-bottom: 15px;
      font-size: 14px;
    }

    .ticket-container {
      max-width: 800px;
      margin: 0 auto;
      background: white;
      box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }

    .ticket-header {
      background: #1a365d;
      color: white;
      padding: 12px 20px;
    }

    .ticket-header h1 {
      font-size: 20px;
      font-weight: bold;
    }

    .ticket-header p {
      font-size: 13px;
      opacity: 0.9;
      font-weight: 600;
    }

    .ticket-section-header {
      background: #1e3a5f;
      color: white;
      padding: 8px 20px;
      font-size: 14px;
      font-weight: 600;
      display: table;
      width: 100%;
    }

    .ticket-section-header-left,
    .ticket-section-header-right {
      display: table-cell;
      vertical-align: middle;
    }

    .ticket-section-header-left {
      width: 70%;
    }

    .ticket-section-header-right {
      width: 30%;
      text-align: right;
      font-size: 12px;
      font-weight: normal;
      opacity: 0.9;
    }

    .ticket-content {
      padding: 15px 20px;
      border-bottom: 1px solid #cbd5e0;
    }

    .ticket-label {
      color: #718096;
      font-size: 11px;
      font-weight: 600;
      text-transform: uppercase;
      margin-bottom: 2px;
    }

    .ticket-value {
      color: #1a202c;
      font-size: 13px;
      font-weight: 500;
    }

    .ticket-highlight {
      color: #0077cc;
    }

    .info-grid {
      display: table;
      width: 100%;
      border-collapse: separate;
      border-spacing: 30px 0;
    }

    .info-box {
      display: table-cell;
      vertical-align: top;
      width: 50%;
      background: #f7fafc;
      padding: 15px;
      border-left: 3px solid #1e3a5f;
    }

    .flight-row {
      display: table;
      width: 100%;
      border-collapse: collapse;
      padding: 15px 0;
    }

    .flight-row:last-of-type {
      border-bottom: none;
    }

    .airline-info {
      display: table-cell;
      vertical-align: top;
      width: 140px;
      padding-right: 15px;
    }

    .airline-logo {
  width: 78px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-bottom: 5px;
}

.airline-logo img {
  width: 100%;
  height: auto;
  max-height: 48px;
  object-fit: contain;
}


    .text-muted {
      color: #718096;
      font-size: 11px;
    }

    .airline-remark {
      color: #0077cc;
      font-size: 11px;
      padding: 8px 20px;
      border-top: 1px solid #e2e8f0;
    }

    .layover-notice {
      text-align: center;
      padding: 10px;
      color: #718096;
      font-size: 12px;
      border-top: 1px dashed #cbd5e0;
      border-bottom: 1px dashed #cbd5e0;
    }

    .passenger-grid {
      display: table;
      width: 100%;
      border-collapse: separate;
      border-spacing: 20px 0;
      margin-bottom: 15px;
    }

    .passenger-title {
      font-weight: 600;
      font-size: 14px;
      margin-bottom: 15px;
      color: #1a202c;
    }

    .mt-1 { margin-top: 4px; }
    .font-semibold { font-weight: 600; }

    .departure-cell,
    .arrival-cell,
    .duration-cell,
    .baggage-cell {
      display: table-cell;
      vertical-align: top;
      padding-right: 15px;
    }

    .departure-cell {
      width: 25%;
    }

    .arrival-cell {
      width: 25%;
    }

    .duration-cell,
    .baggage-cell {
      width: 25%;
    }

    .ticket-footer {
      background: #f7fafc;
      padding: 20px;
      margin-top: 20px;
      border-top: 2px solid #e2e8f0;
    }

    .footer-section {
      margin-bottom: 20px;
    }

    .footer-section:last-child {
      margin-bottom: 0;
    }

    .footer-title {
      font-weight: 600;
      font-size: 13px;
      color: #1a202c;
      margin-bottom: 8px;
    }

    .footer-content {
      font-size: 11px;
      color: #4a5568;
      line-height: 1.6;
    }

    .footer-content ul {
      list-style: none;
      padding: 0;
      margin: 4px 0;
    }

    .footer-content li {
      margin-bottom: 4px;
    }

    .footer-link {
      color: #0077cc;
      text-decoration: underline;
    }
  </style>
</head>
<body>

  <div class="ticket-container">
    <!-- Header -->
    <div class="ticket-header">
      <h1>Booked Itinerary</h1>
      <p>PNR-{{ strtoupper($pnr ?? 'N/A') }}</p>
    </div>

    <!-- Passenger & Agency Info -->
    <div class="ticket-content">
      <div class="info-grid">
        <div class="info-box">
          <div class="ticket-label">Passenger</div>
          <div class="ticket-value font-semibold" style="font-size: 15px;">
            @php
              $passengerName = '';
              if (!empty($passengers) && isset($passengers[0])) {
                $firstPassenger = $passengers[0];
                
                // Try multiple variations for title field
                $title = '';
                $titleFields = ['title', 'Title', 'TITLE', 'salutation', 'Salutation', 'prefix', 'Prefix', 'nameTitle', 'name_title', 'passengerTitle', 'passenger_title'];
                foreach ($titleFields as $field) {
                  if (!empty($firstPassenger[$field])) {
                    $title = trim($firstPassenger[$field]);
                    break;
                  }
                }
                
                $firstName = $firstPassenger['firstName'] ?? $firstPassenger['first_name'] ?? $firstPassenger['FirstName'] ?? '';
                $lastName = $firstPassenger['lastName'] ?? $firstPassenger['last_name'] ?? $firstPassenger['LastName'] ?? '';
                
                if (!empty($firstName) || !empty($lastName)) {
                  $passengerName = trim($lastName . ' ' . $firstName);
                  if (!empty($title)) {
                    // Ensure title has proper spacing
                    $passengerName = trim($title . ' ' . $passengerName);
                  }
                } elseif (!empty($title)) {
                  // If only title is available, use it
                  $passengerName = $title;
                }
              }
            @endphp
            @if(!empty($passengerName))
              {{ $passengerName }}
            @else
              -
            @endif
          </div>
        </div>
        <div class="info-box">
          <div class="ticket-label">Air proof ltd</div>
          <div class="ticket-value mt-1" style="font-size: 12px;">71-75 shelton street, covent garden london, United Kingdom</div>
          <div class="ticket-value" style="font-size: 12px;"><span class="font-semibold">Phone:</span> +21263587199</div>
          <div class="ticket-value" style="font-size: 12px;"><span class="font-semibold">E-mail:</span> Contact@reservationpourvisa.com</div>
        </div>
      </div>
    </div>

    <!-- Flights: Multi-segment support -->
    @php
      // Use normalized trips if available, otherwise fallback to old structure
      $trips = $trips ?? [];
      
      // Fallback: Create trips from old data structure if trips not provided
      if (empty($trips)) {
        $hasReturn = !empty($dates['return']);
        $trips = [
          [
            'title' => 'Departure Flight Details',
            'total_duration' => '8 hrs 15 min',
            'segments' => [
              [
                'airline' => $airline ?? 'Air Canada',
                'flight_number' => $flightNumber ?? 'AC - 73',
                'from_code' => $route['origin'] ?? 'CMN',
                'from_name' => 'Mohamed V',
                'from_terminal' => '2',
                'to_code' => $route['destination'] ?? 'YUL',
                'to_name' => 'Dorval',
                'to_terminal' => '2',
                'departure_time' => $dates['departure'] ?? null,
                'arrival_time' => null,
                'duration' => '8 hrs 15 min',
                'cabin' => 'Economy',
                'baggage' => 'Check in: 2 PC(s)',
              ]
            ]
          ]
        ];
        
        if ($hasReturn) {
          $trips[] = [
            'title' => 'Return Flight Details',
            'total_duration' => '7 hrs 5 min',
            'segments' => [
              [
                'airline' => $airline ?? 'Air Canada',
                'flight_number' => $flightNumber ?? 'AC - 73',
                'from_code' => $route['destination'] ?? 'YUL',
                'from_name' => 'Dorval',
                'from_terminal' => '2',
                'to_code' => $route['origin'] ?? 'CMN',
                'to_name' => 'Mohamed V',
                'to_terminal' => '2',
                'departure_time' => $dates['return'] ?? null,
                'arrival_time' => null,
                'duration' => '7 hrs 5 min',
                'cabin' => 'Economy',
                'baggage' => 'Check in: 2 PC(s)',
              ]
            ]
          ];
        }
      }
    @endphp

    @foreach($trips as $tripIndex => $trip)
      <!-- Trip Header -->
      <div class="ticket-section-header">
        <div class="ticket-section-header-left">
          <span><img src="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' viewBox='0 0 24 24' fill='white'%3E%3Cpath d='M21 16v-2l-8-5V3.5c0-.83-.67-1.5-1.5-1.5S10 2.67 10 3.5V9l-8 5v2l8-2.5V19l-2 1.5V22l3.5-1 3.5 1v-1.5L13 19v-5.5l8 2.5z'/%3E%3C/svg%3E" alt="Flight" style="width: 15px; height: 15px; vertical-align: middle; margin-right: 10px;rotate: 45deg;"> {{ $trip['title'] }}</span>
        </div>
        <div class="ticket-section-header-right">
          <span>Total Flight Duration: {{ $trip['total_duration'] ?? '-' }}</span>
        </div>
      </div>

      <!-- Segments -->
      <div class="ticket-content">
        @foreach($trip['segments'] as $segmentIndex => $segment)
          @php
            // Extract airline code for logo - prefer carrier_code from segment
            $segmentAirlineCode = '';
            if (!empty($segment['carrier_code'])) {
              // Use carrier code directly if available
              $segmentAirlineCode = strtoupper($segment['carrier_code']);
            } elseif (!empty($segment['flight_number'])) {
              // Extract from flight number (e.g., "AC73", "AC - 73", "AC-73")
              if (preg_match('/^([A-Z]{2})/i', $segment['flight_number'], $matches)) {
                $segmentAirlineCode = strtoupper($matches[1]);
              } else {
                // Fallback: take first 2 characters
                $segmentAirlineCode = strtoupper(substr($segment['flight_number'], 0, 2));
              }
            } elseif (!empty($segment['airline'])) {
              // Extract first 2 letters from airline name
              $segmentAirlineCode = strtoupper(substr($segment['airline'], 0, 2));
            } else {
              // Final fallback
              $segmentAirlineCode = !empty($airline) ? strtoupper(substr($airline, 0, 2)) : 'AC';
            }

            // Parse departure time
            $departureDateTime = null;
            $departureDay = '';
            $departureDate = '';
            $departureTime = '';
            if (!empty($segment['departure_time'])) {
              try {
                $departureDateTime = \Carbon\Carbon::parse($segment['departure_time']);
                $departureDay = $departureDateTime->format('D');
                $departureDate = $departureDateTime->format('d M y');
                $departureTime = $departureDateTime->format('H:i');
              } catch (\Exception $e) {
                // Ignore
              }
            }

            // Parse arrival time
            $arrivalDateTime = null;
            $arrivalDay = '';
            $arrivalDate = '';
            $arrivalTime = '';
            if (!empty($segment['arrival_time'])) {
              try {
                $arrivalDateTime = \Carbon\Carbon::parse($segment['arrival_time']);
                $arrivalDay = $arrivalDateTime->format('D');
                $arrivalDate = $arrivalDateTime->format('d M y');
                $arrivalTime = $arrivalDateTime->format('H:i');
              } catch (\Exception $e) {
                // Ignore
              }
            }
          @endphp

          <div class="flight-row">
            <div class="airline-info">
              <div class="airline-logo">
                <img src="https://images.daisycon.io/airline/?width=300&height=150&iata={{ $segmentAirlineCode }}" alt="{{ $segment['airline'] ?? 'Airline' }}" onerror="this.style.display='none'; this.parentElement.textContent='{{ $segmentAirlineCode }}'; this.parentElement.style.display='inline-block';">
              </div>
              <div class="ticket-value font-semibold">{{ $segment['airline'] ?? 'Air Canada' }}</div>
              <div class="ticket-value">{{ $segment['flight_number'] ?? 'AC - 73' }}</div>
              <div class="text-muted">Airline Ref. {{ strtoupper(substr($pnr ?? 'N/A', 0, 6)) }}</div>
              <div class="text-muted">Cabin Class: {{ $segment['cabin'] ?? 'Economy' }}</div>
              <div class="text-muted">Operated By {{ $segmentAirlineCode }}</div>
            </div>
            <div class="departure-cell">
              <div class="ticket-label">Departing</div>
              <div class="ticket-value font-semibold ticket-highlight">
                {{ $segment['from_name'] ?? strtoupper($segment['from_code'] ?? 'N/A') }} ({{ strtoupper($segment['from_code'] ?? 'N/A') }})
              </div>
              @if($departureDateTime)
              <div class="ticket-value">{{ $departureDay }}, {{ $departureDate }}, {{ $departureTime }} hrs</div>
              @else
              <div class="ticket-value">-</div>
              @endif
              <div class="text-muted mt-1">{{ $segment['from_name'] ?? strtoupper($segment['from_code'] ?? 'N/A') }}</div>
              @if(!empty($segment['from_terminal']))
              <div class="text-muted">Terminal - {{ $segment['from_terminal'] }}</div>
              @endif
            </div>
            <div class="arrival-cell">
              <div class="ticket-label">Arrival</div>
              <div class="ticket-value font-semibold ticket-highlight">
                {{ $segment['to_name'] ?? strtoupper($segment['to_code'] ?? 'N/A') }} ({{ strtoupper($segment['to_code'] ?? 'N/A') }})
              </div>
              @if($arrivalDateTime)
              <div class="ticket-value">{{ $arrivalDay }}, {{ $arrivalDate }}, {{ $arrivalTime }} hrs</div>
              @else
              <div class="ticket-value">-</div>
              @endif
              <div class="text-muted mt-1">{{ $segment['to_name'] ?? strtoupper($segment['to_code'] ?? 'N/A') }}</div>
              @if(!empty($segment['to_terminal']))
              <div class="text-muted">Terminal - {{ $segment['to_terminal'] }}</div>
              @endif
            </div>
            <div class="duration-cell">
              <div class="ticket-label">Flight Duration</div>
              <div class="ticket-value">{{ $segment['duration'] ?? '-' }}</div>
            </div>
            <div class="baggage-cell">
              <div class="ticket-label">Baggage</div>
              <div class="ticket-value">{{ $segment['baggage'] ?? 'Check in: 2 PC(s)' }}</div>
            </div>
          </div>

          <div class="airline-remark">
            Airline Remarks: Amadeus NDC | VOID window may vary, please check with OPS before proceeding.
          </div>

          @if($segmentIndex < count($trip['segments']) - 1)
            @php
              // Calculate layover time between segments
              $currentSegment = $trip['segments'][$segmentIndex];
              $nextSegment = $trip['segments'][$segmentIndex + 1];
              $layoverMinutes = 0;
              $layoverAirport = strtoupper($currentSegment['to_code'] ?? 'N/A');
              
              if (!empty($currentSegment['arrival_time']) && !empty($nextSegment['departure_time'])) {
                try {
                  $arrival = \Carbon\Carbon::parse($currentSegment['arrival_time']);
                  $nextDeparture = \Carbon\Carbon::parse($nextSegment['departure_time']);
                  $layoverMinutes = $arrival->diffInMinutes($nextDeparture);
                } catch (\Exception $e) {
                  // Ignore
                }
              }
              
              $layoverHours = floor($layoverMinutes / 60);
              $layoverMins = $layoverMinutes % 60;
              $layoverText = '';
              if ($layoverHours > 0 && $layoverMins > 0) {
                $layoverText = $layoverHours . ' hrs ' . $layoverMins . ' min';
              } elseif ($layoverHours > 0) {
                $layoverText = $layoverHours . ' hrs';
              } elseif ($layoverMins > 0) {
                $layoverText = $layoverMins . ' min';
              } else {
                $layoverText = '0 min';
              }
            @endphp
            <div class="layover-notice">
              --------------Layover: {{ $layoverText }}, {{ $layoverAirport }}--------------
            </div>
          @endif
        @endforeach
      </div>


    @endforeach

    <!-- Footer -->
    <div class="ticket-footer">
      @php
        // Collect unique airline references from segments
        $airlineReferences = [];
        foreach ($trips as $trip) {
          foreach ($trip['segments'] as $segment) {
            $carrierCode = $segment['carrier_code'] ?? '';
            $airlineName = $segment['airline'] ?? '';
            $airlineRef = strtoupper(substr($pnr ?? 'N/A', 0, 6));
            
            if (!empty($carrierCode) && !isset($airlineReferences[$carrierCode])) {
              $airlineReferences[$carrierCode] = [
                'code' => $carrierCode,
                'name' => $airlineName,
                'ref' => $airlineRef,
              ];
            }
          }
        }
      @endphp

      <!-- Ecological Information -->
      <div class="footer-section">
        <div class="footer-title">Informations écologiques</div>
        <div class="footer-content">
          Les émissions moyennes de CO2 calculées sont de 1 028,70 kg/personne<br>
          Source: ICAO Carbon Emissions Calculator<br>
          <a href="https://www.icao.int/environmental-protection/CarbonOffset/Pages/default.aspx" class="footer-link">https://www.icao.int/environmental-protection/CarbonOffset/Pages/default.aspx</a>
        </div>
      </div>

      <!-- Airline File References -->
      @if(!empty($airlineReferences))
      <div class="footer-section">
        <div class="footer-title">Référence(s) dossier compagnie aérienne</div>
        <div class="footer-content">
          <ul>
            @foreach($airlineReferences as $ref)
            <li>{{ $ref['code'] }} ({{ $ref['name'] }}): {{ $ref['ref'] }}</li>
            @endforeach
          </ul>
        </div>
      </div>
      @endif

      <!-- Data Protection Notice -->
      <div class="footer-section">
        <div class="footer-title">Avis de protection des données</div>
        <div class="footer-content">
          vos données personnelles seront traitées conformément à la politique de protection des données de la compagnie aérienne correspondante et, si vous avez réservé via un système global de distribution ("GDS"), avec sa politique de protection des données. Ces politiques sont disponibles ici: <a href="http://www.iatatravelcenter.com/privacy" class="footer-link">http://www.iatatravelcenter.com/privacy</a> ou auprès de la compagnie aérienne ou du GDS directement. Vous devriez lire cette documentation, qui s'applique à votre réservation et spécifie, par exemple, comment vos données personnelles sont collectées, stockées, utilisées, publiées et transférées. (S'applique aussi pour des itinéraires incluant plusieurs compagnies aériennes)
        </div>
      </div>
    </div>

  </div>

</body>
</html>
