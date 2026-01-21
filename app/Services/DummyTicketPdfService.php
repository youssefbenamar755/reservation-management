<?php

namespace App\Services;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\View;

class DummyTicketPdfService
{
    /**
     * Generate a dummy ticket PDF for a single passenger
     *
     * @param string $pnr PNR code
     * @param array $flightOffer The flight offer used for PNR creation
     * @param array $passenger Single passenger: ['title','first_name','last_name','email','phone']
     * @param array $websiteInfo Website information (optional)
     * @return string File path relative to storage/app/public
     */
    public function generate(string $pnr, array $flightOffer, array $passenger, ?array $websiteInfo = null): string
    {
        try {
            // Extract passenger info
            $firstName = strtoupper($passenger['first_name'] ?? 'PASSENGER');
            $lastName = strtoupper($passenger['last_name'] ?? 'TEST');
            $title = strtoupper($passenger['title'] ?? 'MR');
            $email = $passenger['email'] ?? '';
            $phone = $passenger['phone'] ?? '';

            // Extract route and dates from flight offer
            $route = $this->extractRouteFromFlightOffer($flightOffer);
            $dates = $this->extractDatesFromFlightOffer($flightOffer);

            // Extract airline and flight number from flight offer
            $extracted = $this->extractAirlineAndFlightNumber($flightOffer);
            $airline = $extracted['airline'] ?? null;
            $flightNumber = $extracted['flightNumber'] ?? null;

            // Format dates for display
            $formattedDates = [
                'departure' => $this->formatDateForDisplay($dates['departure'] ?? null),
                'return' => !empty($dates['return']) ? $this->formatDateForDisplay($dates['return']) : null,
            ];

            // Format passenger data for template (single passenger)
            $passengers = [[
                'firstName' => $firstName,
                'lastName' => $lastName,
                'title' => $title,
                'dateOfBirth' => null,
            ]];

            $formattedPassengerDates = [null]; // No DOB for now

            // Booking date (current date)
            $bookingDate = now()->format('F j, Y');
            $generatedAt = now()->format('F j, Y \a\t g:i A');

            // Normalize flight data into trips structure (for multi-segment support)
            $trips = $this->normalizeFlightDataToTrips($flightOffer, $route, $dates, $airline, $flightNumber);

            // Generate HTML using Blade template
            $templateName = 'pdf.dummy-ticket';
            $templatePath = resource_path('views/pdf/dummy-ticket.blade.php');
            
            // Verify template exists
            if (!file_exists($templatePath)) {
                Log::error('PDF template not found', [
                    'template' => $templateName,
                    'expected_path' => $templatePath,
                ]);
                throw new \RuntimeException('PDF template not found: ' . $templateName);
            }
            
            Log::info('Generating PDF for single passenger', [
                'template' => $templateName,
                'passenger' => $firstName . ' ' . $lastName,
                'pnr' => $pnr,
            ]);
            
            $html = View::make($templateName, [
                'pnr' => $pnr,
                'route' => $route,
                'dates' => $dates,
                'formattedDates' => $formattedDates,
                'passengers' => $passengers,
                'formattedPassengerDates' => $formattedPassengerDates,
                'airline' => $airline,
                'flightNumber' => $flightNumber,
                'bookingDate' => $bookingDate,
                'generatedAt' => $generatedAt,
                'trips' => $trips,
            ])->render();

            // Configure Dompdf
            $options = new Options();
            $options->setIsHtml5ParserEnabled(true);
            $options->setIsRemoteEnabled(true);
            $options->setDefaultFont('Helvetica');
            $options->setChroot(public_path());

            $dompdf = new Dompdf($options);
            $dompdf->loadHtml($html);
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            // Generate filename - format: Itinerary-{passengerName} - {pnr}.pdf
            $passengerName = trim($lastName . ' ' . $firstName);
            if (empty($passengerName)) {
                $passengerName = 'Passenger';
            }
            
            // Sanitize passenger name for filesystem
            $passengerName = preg_replace('/[^a-zA-Z0-9\s\-_]/', '', $passengerName);
            $passengerName = preg_replace('/\s+/', '-', trim($passengerName));
            $passengerName = strtoupper($passengerName);
            
            // Generate filename: Itinerary-{passengerName} - {pnr}.pdf
            $filename = 'dummy-tickets/' . date('Y/m/') . 'Itinerary-' . $passengerName . ' - ' . strtoupper($pnr) . '.pdf';
            
            // Ensure directory exists
            $directory = storage_path('app/public/' . dirname($filename));
            if (!is_dir($directory)) {
                mkdir($directory, 0755, true);
            }

            // Save PDF
            $fullPath = storage_path('app/public/' . $filename);
            file_put_contents($fullPath, $dompdf->output());

            Log::info('Dummy ticket PDF generated for passenger', [
                'filename' => $filename,
                'pnr' => $pnr,
                'passenger' => $firstName . ' ' . $lastName,
                'full_path' => $fullPath,
            ]);

            // Return path relative to storage/app/public
            return $filename;
        } catch (\Exception $e) {
            Log::error('Failed to generate ticket PDF', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'pnr' => $pnr,
                'passenger' => $passenger,
            ]);
            throw new \RuntimeException('Failed to generate ticket PDF: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate a dummy ticket PDF (legacy method for backward compatibility)
     * @deprecated Use generate() instead
     */
    public function generatePdf(array $data): string
    {
        // Extract first passenger for legacy support
        $passengers = $data['passengers'] ?? [];
        if (empty($passengers)) {
            throw new \RuntimeException('No passengers provided');
        }

        $firstPassenger = $passengers[0];
        $pnr = $data['pnr'] ?? 'N/A';
        
        // Convert to normalized format
        $normalizedPassenger = [
            'title' => $firstPassenger['title'] ?? 'MR',
            'first_name' => $firstPassenger['firstName'] ?? $firstPassenger['first_name'] ?? 'PASSENGER',
            'last_name' => $firstPassenger['lastName'] ?? $firstPassenger['last_name'] ?? 'TEST',
            'email' => $firstPassenger['email'] ?? $firstPassenger['contact']['emailAddress'] ?? '',
            'phone' => $firstPassenger['phone'] ?? '',
        ];

        // Create flight offer from data
        $flightOffer = $data['flightData'] ?? $data;
        
        return $this->generate($pnr, $flightOffer, $normalizedPassenger);
    }

    /**
     * Extract route from flight offer
     */
    private function extractRouteFromFlightOffer(array $flightOffer): array
    {
        $route = ['origin' => null, 'destination' => null];
        
        if (!empty($flightOffer['itineraries']) && is_array($flightOffer['itineraries'])) {
            $firstItinerary = $flightOffer['itineraries'][0];
            $segments = $firstItinerary['segments'] ?? [];
            
            if (!empty($segments)) {
                $route['origin'] = $segments[0]['departure']['iataCode'] ?? null;
                
                $lastSegment = $segments[count($segments) - 1];
                $route['destination'] = $lastSegment['arrival']['iataCode'] ?? null;
            }
        }

        return $route;
    }

    /**
     * Extract dates from flight offer
     */
    private function extractDatesFromFlightOffer(array $flightOffer): array
    {
        $dates = ['departure' => null, 'return' => null];
        
        if (!empty($flightOffer['itineraries']) && is_array($flightOffer['itineraries'])) {
            // Departure date from first itinerary
            if (!empty($flightOffer['itineraries'][0])) {
                $firstItinerary = $flightOffer['itineraries'][0];
                $segments = $firstItinerary['segments'] ?? [];
                
                if (!empty($segments) && !empty($segments[0]['departure']['at'])) {
                    try {
                        $date = new \DateTime($segments[0]['departure']['at']);
                        $dates['departure'] = $date->format('Y-m-d');
                    } catch (\Exception $e) {
                        // Ignore
                    }
                }
            }
            
            // Return date from second itinerary (if exists)
            if (!empty($flightOffer['itineraries'][1])) {
                $returnItinerary = $flightOffer['itineraries'][1];
                $segments = $returnItinerary['segments'] ?? [];
                
                if (!empty($segments) && !empty($segments[0]['departure']['at'])) {
                    try {
                        $date = new \DateTime($segments[0]['departure']['at']);
                        $dates['return'] = $date->format('Y-m-d');
                    } catch (\Exception $e) {
                        // Ignore
                    }
                }
            }
        }

        return $dates;
    }

    /**
     * Extract airline and flight number from flight data
     */
    private function extractAirlineAndFlightNumber($data): array
    {
        $airline = null;
        $flightNumber = null;

        if (empty($data) || !is_array($data)) {
            return ['airline' => null, 'flightNumber' => null];
        }

        // Try to extract from flight data structure (Amadeus format)
        if (isset($data['itineraries']) && is_array($data['itineraries']) && !empty($data['itineraries'])) {
            $firstItinerary = $data['itineraries'][0];
            $segments = $firstItinerary['segments'] ?? [];
            
            if (!empty($segments)) {
                $firstSegment = $segments[0];
                
                // Extract airline code
                if (isset($firstSegment['carrierCode'])) {
                    $airline = $this->getAirlineName($firstSegment['carrierCode']);
                }
                
                // Extract flight number
                if (isset($firstSegment['number'])) {
                    $carrierCode = $firstSegment['carrierCode'] ?? '';
                    $flightNumber = $carrierCode . $firstSegment['number'];
                }
            }

            // Try validatingAirlineCodes as fallback
            if (empty($airline) && isset($data['validatingAirlineCodes']) && !empty($data['validatingAirlineCodes'])) {
                $airlineCode = is_array($data['validatingAirlineCodes']) ? $data['validatingAirlineCodes'][0] : $data['validatingAirlineCodes'];
                $airline = $this->getAirlineName($airlineCode);
            }
        }

        // Fallback: Check for direct airline/flight_number keys
        if (empty($airline)) {
            foreach ($data as $key => $value) {
                $keyLower = strtolower($key);
                if (strpos($keyLower, 'airline') !== false && is_string($value) && !empty($value)) {
                    $airline = trim($value);
                    break;
                }
            }
        }

        if (empty($flightNumber)) {
            foreach ($data as $key => $value) {
                $keyLower = strtolower($key);
                if ((strpos($keyLower, 'flight') !== false && strpos($keyLower, 'number') !== false) ||
                    $keyLower === 'flight_number' || $keyLower === 'flightnumber') {
                    if (is_string($value) && !empty($value)) {
                        $flightNumber = trim($value);
                        break;
                    }
                }
            }
        }

        return [
            'airline' => $airline,
            'flightNumber' => $flightNumber,
        ];
    }

    /**
     * Get airline name from IATA code (common airlines)
     */
    private function getAirlineName(?string $code): ?string
    {
        if (empty($code)) {
            return null;
        }

        $code = strtoupper(trim($code));

        // Common airline code to name mapping
        $airlines = [
            'AA' => 'American Airlines',
            'UA' => 'United Airlines',
            'DL' => 'Delta Air Lines',
            'BA' => 'British Airways',
            'LH' => 'Lufthansa',
            'AF' => 'Air France',
            'KL' => 'KLM Royal Dutch Airlines',
            'EK' => 'Emirates',
            'QR' => 'Qatar Airways',
            'EY' => 'Etihad Airways',
            'SQ' => 'Singapore Airlines',
            'CX' => 'Cathay Pacific',
            'QF' => 'Qantas',
            'VS' => 'Virgin Atlantic',
            'IB' => 'Iberia',
            'LX' => 'Swiss International Air Lines',
            'OS' => 'Austrian Airlines',
            'SN' => 'Brussels Airlines',
        ];

        return $airlines[$code] ?? $code; // Return code if name not found
    }

    /**
     * Format date for display
     */
    private function formatDateForDisplay(?string $date): string
    {
        if (empty($date) || $date === 'N/A') {
            return 'N/A';
        }

        try {
            // Try parsing as ISO date (Y-m-d or Y-m-d H:i:s)
            if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
                $dateObj = new \DateTime($date);
                return $dateObj->format('F j, Y');
            }

            // Try other formats
            $dateObj = new \DateTime($date);
            return $dateObj->format('F j, Y');
        } catch (\Exception $e) {
            // If parsing fails, return as-is
            return $date;
        }
    }

    /**
     * Normalize flight data into trips structure for multi-segment support
     * 
     * @param array|null $flightData Flight data with itineraries/segments
     * @param array $route Fallback route data
     * @param array $dates Fallback dates data
     * @param string|null $airline Fallback airline
     * @param string|null $flightNumber Fallback flight number
     * @return array Normalized trips structure
     */
    private function normalizeFlightDataToTrips(?array $flightData, array $route, array $dates, ?string $airline, ?string $flightNumber): array
    {
        $trips = [];

        // If we have structured flight data with itineraries, use it
        if (!empty($flightData) && is_array($flightData) && isset($flightData['itineraries']) && is_array($flightData['itineraries'])) {
            $itineraries = $flightData['itineraries'];
            
            foreach ($itineraries as $index => $itinerary) {
                $segments = $itinerary['segments'] ?? [];
                
                if (empty($segments)) {
                    continue;
                }

                $tripTitle = $index === 0 ? 'Departure Flight Details' : 'Return Flight Details';
                $normalizedSegments = [];
                $totalDurationMinutes = 0;

                foreach ($segments as $segment) {
                    $normalizedSegment = $this->normalizeSegment($segment, $flightData);
                    if ($normalizedSegment) {
                        $normalizedSegments[] = $normalizedSegment;
                        $totalDurationMinutes += $normalizedSegment['duration_minutes'] ?? 0;
                    }
                }

                if (!empty($normalizedSegments)) {
                    $trips[] = [
                        'title' => $tripTitle,
                        'total_duration' => $this->formatDuration($totalDurationMinutes),
                        'segments' => $normalizedSegments,
                    ];
                }
            }
        }

        // Fallback: Create simple trip from route/dates if no structured data
        if (empty($trips)) {
            $departureSegment = $this->createFallbackSegment($route, $dates, $airline, $flightNumber, false);
            
            if ($departureSegment) {
                $trips[] = [
                    'title' => 'Departure Flight Details',
                    'total_duration' => $departureSegment['duration'] ?? '8 hrs 15 min',
                    'segments' => [$departureSegment],
                ];
            }

            // Add return if exists
            if (!empty($dates['return'])) {
                $returnSegment = $this->createFallbackSegment($route, $dates, $airline, $flightNumber, true);
                
                if ($returnSegment) {
                    $trips[] = [
                        'title' => 'Return Flight Details',
                        'total_duration' => $returnSegment['duration'] ?? '7 hrs 5 min',
                        'segments' => [$returnSegment],
                    ];
                }
            }
        }

        return $trips;
    }

    /**
     * Normalize a single segment from flight data
     */
    private function normalizeSegment(array $segment, ?array $flightData = null): ?array
    {
        try {
            $departure = $segment['departure'] ?? [];
            $arrival = $segment['arrival'] ?? [];
            
            $fromCode = $departure['iataCode'] ?? null;
            $toCode = $arrival['iataCode'] ?? null;
            
            if (empty($fromCode) || empty($toCode)) {
                return null;
            }

            $carrierCode = $segment['carrierCode'] ?? '';
            $flightNum = $segment['number'] ?? '';
            $airlineName = $this->getAirlineName($carrierCode) ?? $carrierCode;

            // Parse dates
            $departureAt = $departure['at'] ?? null;
            $arrivalAt = $arrival['at'] ?? null;

            // Calculate duration
            $durationMinutes = 0;
            $duration = $segment['duration'] ?? null;
            if ($duration) {
                $durationMinutes = $this->parseIso8601Duration($duration);
            } elseif ($departureAt && $arrivalAt) {
                try {
                    $dep = new \DateTime($departureAt);
                    $arr = new \DateTime($arrivalAt);
                    $diff = $arr->diff($dep);
                    $durationMinutes = ($diff->days * 24 * 60) + ($diff->h * 60) + $diff->i;
                } catch (\Exception $e) {
                    // Ignore
                }
            }

            // Extract cabin class from travelerPricings if available
            $cabin = 'Economy';
            if (!empty($flightData['travelerPricings']) && is_array($flightData['travelerPricings'])) {
                foreach ($flightData['travelerPricings'] as $travelerPricing) {
                    $fareDetails = $travelerPricing['fareDetailsBySegment'] ?? [];
                    if (!empty($fareDetails) && isset($fareDetails[0]['cabin'])) {
                        $cabinRaw = $fareDetails[0]['cabin'];
                        $cabin = ucfirst(strtolower($cabinRaw));
                        break;
                    }
                }
            }

            // Extract baggage allowance
            $baggage = 'Check in: 2 PC(s)';
            if (isset($segment['baggageAllowance']) && !empty($segment['baggageAllowance'])) {
                $baggageData = $segment['baggageAllowance'];
                if (isset($baggageData['quantity'])) {
                    $baggage = 'Check in: ' . $baggageData['quantity'] . ' PC(s)';
                }
            }

            return [
                'airline' => $airlineName,
                'carrier_code' => $carrierCode, // Store carrier code separately for logo
                'flight_number' => $carrierCode . $flightNum,
                'from_code' => $fromCode,
                'from_name' => $departure['cityName'] ?? $departure['name'] ?? $this->getAirportName($fromCode),
                'from_terminal' => $departure['terminal'] ?? null,
                'to_code' => $toCode,
                'to_name' => $arrival['cityName'] ?? $arrival['name'] ?? $this->getAirportName($toCode),
                'to_terminal' => $arrival['terminal'] ?? null,
                'departure_time' => $departureAt,
                'arrival_time' => $arrivalAt,
                'duration' => $this->formatDuration($durationMinutes),
                'duration_minutes' => $durationMinutes,
                'cabin' => $cabin,
                'baggage' => $baggage,
            ];
        } catch (\Exception $e) {
            Log::warning('Failed to normalize segment', [
                'error' => $e->getMessage(),
                'segment' => $segment,
            ]);
            return null;
        }
    }

    /**
     * Create fallback segment from route/dates (for backward compatibility)
     */
    private function createFallbackSegment(array $route, array $dates, ?string $airline, ?string $flightNumber, bool $isReturn): ?array
    {
        $origin = $route['origin'] ?? 'CMN';
        $destination = $route['destination'] ?? 'YUL';
        
        $fromCode = $isReturn ? $destination : $origin;
        $toCode = $isReturn ? $origin : $destination;
        $date = $isReturn ? ($dates['return'] ?? null) : ($dates['departure'] ?? null);

        if (empty($date)) {
            return null;
        }

        try {
            $dateTime = new \DateTime($date);
            $departureTime = $dateTime->format('Y-m-d\TH:i:s');
            
            // Add default duration (8h15 for departure, 7h5 for return)
            $durationMinutes = $isReturn ? 425 : 495; // 7h5 = 425 min, 8h15 = 495 min
            $arrivalDateTime = clone $dateTime;
            $arrivalDateTime->modify('+' . $durationMinutes . ' minutes');
            $arrivalTime = $arrivalDateTime->format('Y-m-d\TH:i:s');
        } catch (\Exception $e) {
            return null;
        }

        // Extract carrier code from flight number or airline
        $carrierCode = 'AC';
        if (!empty($flightNumber)) {
            // Try to extract from flight number (e.g., "AC73", "AC - 73", "AC-73")
            if (preg_match('/^([A-Z]{2})/i', $flightNumber, $matches)) {
                $carrierCode = strtoupper($matches[1]);
            }
        } elseif (!empty($airline)) {
            // Extract first 2 letters from airline name
            $carrierCode = strtoupper(substr($airline, 0, 2));
        }

        return [
            'airline' => $airline ?? 'Air Canada',
            'carrier_code' => $carrierCode, // Store carrier code separately for logo
            'flight_number' => $flightNumber ?? 'AC - 73',
            'from_code' => $fromCode,
            'from_name' => $this->getAirportName($fromCode),
            'from_terminal' => '2',
            'to_code' => $toCode,
            'to_name' => $this->getAirportName($toCode),
            'to_terminal' => '2',
            'departure_time' => $departureTime,
            'arrival_time' => $arrivalTime,
            'duration' => $this->formatDuration($durationMinutes),
            'duration_minutes' => $durationMinutes,
            'cabin' => 'Economy',
            'baggage' => 'Check in: 2 PC(s)',
        ];
    }

    /**
     * Parse ISO 8601 duration (e.g., "PT2H30M") to minutes
     */
    private function parseIso8601Duration(string $duration): int
    {
        $minutes = 0;
        
        // Match hours
        if (preg_match('/(\d+)H/', $duration, $matches)) {
            $minutes += (int)$matches[1] * 60;
        }
        
        // Match minutes
        if (preg_match('/(\d+)M/', $duration, $matches)) {
            $minutes += (int)$matches[1];
        }
        
        return $minutes;
    }

    /**
     * Format duration in minutes to "X hrs Y min"
     */
    private function formatDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '-';
        }
        
        $hours = floor($minutes / 60);
        $mins = $minutes % 60;
        
        if ($hours > 0 && $mins > 0) {
            return $hours . ' hrs ' . $mins . ' min';
        } elseif ($hours > 0) {
            return $hours . ' hrs';
        } else {
            return $mins . ' min';
        }
    }

    /**
     * Get airport name from IATA code (common airports)
     */
    private function getAirportName(string $code): string
    {
        $code = strtoupper(trim($code));
        
        $airports = [
            'CMN' => 'Mohamed V',
            'YUL' => 'Dorval',
            'BCN' => 'Barcelona-El Prat',
            'ORY' => 'Paris Orly',
            'CDG' => 'Charles de Gaulle',
            'JFK' => 'John F. Kennedy International',
            'LAX' => 'Los Angeles International',
            'LHR' => 'London Heathrow',
            'DXB' => 'Dubai International',
            'FRA' => 'Frankfurt',
            'AMS' => 'Amsterdam Schiphol',
        ];
        
        return $airports[$code] ?? $code;
    }
}

