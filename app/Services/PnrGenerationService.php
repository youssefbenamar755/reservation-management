<?php

namespace App\Services;

use App\Models\FfSubmission;
use Illuminate\Support\Facades\Log;

class PnrGenerationService
{
    public function __construct(
        private AmadeusService $amadeusService
    ) {}


    /**
     * Generate PNR for a submission
     *
     * @param FfSubmission $submission
     * @return array [
     *   'pnr' => string,
     *   'pnr_data' => array,
     *   'source' => 'amadeus_direct'|'amadeus_search',
     *   'used_offer' => array, // The flight offer that was successfully used
     *   'extracted_data' => [
     *     'passengers' => array, // Normalized: [['title','first_name','last_name','email','phone'], ...]
     *     'route' => ['origin' => string, 'destination' => string],
     *     'dates' => ['departure' => string, 'return' => string|null],
     *     'email' => string|null,
     *     'phone' => string|null
     *   ]
     * ]
     */
    public function generatePnr(FfSubmission $submission): array
    {
        $payload = $submission->payload ?? [];
        
        // Log payload BEFORE extraction to debug
        Log::info('Starting PNR generation', [
            'submission_id' => $submission->id,
            'payload_type' => gettype($payload),
            'payload_is_array' => is_array($payload),
            'payload_is_empty' => empty($payload),
            'payload_keys' => is_array($payload) ? array_keys($payload) : 'NOT_ARRAY',
            'payload_count' => is_array($payload) ? count($payload) : 0,
            'payload_preview' => is_array($payload) ? array_slice($payload, 0, 5, true) : $payload,
        ]);
        
        // Extract form response from payload - checks all possible paths
        $response = [];
        try {
            $response = $this->extractFormResponse($payload, $submission->id);
        } catch (\RuntimeException $e) {
            // Re-throw with original message
            throw $e;
        }
        
        // Log available keys BEFORE any validation
        Log::info('Normalized response for PNR generation', [
            'submission_id' => $submission->id,
            'response_keys' => is_array($response) ? array_keys($response) : [],
            'response_type' => gettype($response),
            'response_count' => is_array($response) ? count($response) : 0,
            'response_is_empty' => empty($response),
        ]);

        // Check if website is reservationpourvisa (always search first)
        $website = $submission->website;
        $isReservationPourVisa = $website && (
            stripos($website->name, 'reservationpourvisa') !== false ||
            stripos($website->slug, 'reservationpourvisa') !== false
        );

        // CASE DETECTION: Check if flight_json_data exists AND not reservationpourvisa
        $flightJsonData = $isReservationPourVisa ? null : $this->extractFlightJsonData($response);

        if ($flightJsonData) {
            // CASE A: Direct PNR creation from flight_json_data (with fallback)
            return $this->createPnrFromFlightData($flightJsonData, $submission, $response);
        } else {
            // CASE B: Search for flights first, then create PNR (with fallback)
            return $this->createPnrFromFormFields($submission, $response);
        }
    }

    /**
     * Extract form response from payload - checks all possible paths
     * 
     * @param array|string|null $payload
     * @param int|null $submissionId
     * @return array
     */
    private function extractFormResponse($payload, ?int $submissionId = null): array
    {
        // Handle case where payload might still be a string (shouldn't happen with cast, but be safe)
        if (is_string($payload)) {
            Log::warning('Payload is still a string, attempting to decode', [
                'submission_id' => $submissionId,
                'payload_length' => strlen($payload),
                'payload_preview' => substr($payload, 0, 200),
            ]);
            try {
                $decoded = json_decode($payload, true);
                if (is_array($decoded)) {
                    $payload = $decoded;
                } else {
                    Log::error('Failed to decode payload string', [
                        'submission_id' => $submissionId,
                    ]);
                    return [];
                }
            } catch (\Exception $e) {
                Log::error('Exception decoding payload string', [
                    'submission_id' => $submissionId,
                    'error' => $e->getMessage(),
                ]);
                return [];
            }
        }
        
        // Ensure payload is an array
        if (!is_array($payload)) {
            Log::error('Payload is not an array after processing', [
                'submission_id' => $submissionId,
                'payload_type' => gettype($payload),
            ]);
            return [];
        }
        
        // If payload is empty, return early
        if (empty($payload)) {
            Log::error('Payload is empty', [
                'submission_id' => $submissionId,
            ]);
            return [];
        }
        
        // Try multiple possible paths in order of likelihood
        $possiblePaths = [
            ['response'],
            ['formData'],
            ['data', 'response'],
            ['submission', 'response'],
            ['data', 'formData'],
            ['submission', 'formData'],
            ['data'],
            ['submission'],
            ['form_data'],
            ['form_data', 'response'],
        ];

        foreach ($possiblePaths as $path) {
            $value = $payload;
            $found = true;
            
            foreach ($path as $key) {
                if (isset($value[$key])) {
                    $value = $value[$key];
                } else {
                    $found = false;
                    break;
                }
            }
            
            if ($found && !empty($value)) {
                // Handle JSON string
                if (is_string($value) && (strpos($value, '{') === 0 || strpos($value, '[') === 0)) {
                    try {
                        $decoded = json_decode($value, true);
                        if (is_array($decoded) && !empty($decoded)) {
                            // Check if decoded value has form fields (not just metadata)
                            $hasFormFields = $this->hasFormFields($decoded);
                            if ($hasFormFields) {
                                Log::info('Found form response at path (JSON string)', [
                                    'submission_id' => $submissionId,
                                    'path' => implode(' -> ', $path),
                                    'keys' => array_keys($decoded),
                                ]);
                                return $decoded;
                            }
                        }
                    } catch (\Exception $e) {
                        Log::warning('Failed to parse JSON string in response', [
                            'submission_id' => $submissionId,
                            'path' => implode(' -> ', $path),
                            'error' => $e->getMessage(),
                        ]);
                        continue;
                    }
                }
                
                // Handle array - check if it has form fields
                if (is_array($value) && !empty($value)) {
                    $hasFormFields = $this->hasFormFields($value);
                    if ($hasFormFields) {
                        Log::info('Found form response at path', [
                            'submission_id' => $submissionId,
                            'path' => implode(' -> ', $path),
                            'keys' => array_keys($value),
                            'count' => count($value),
                        ]);
                        return $value;
                    } else {
                        // Found path but no form fields - continue searching
                        Log::debug('Found path but no form fields, continuing search', [
                            'submission_id' => $submissionId,
                            'path' => implode(' -> ', $path),
                            'keys' => array_keys($value),
                        ]);
                    }
                }
            }
        }

        // If no nested path found, check if payload itself is the response
        if (!empty($payload) && is_array($payload)) {
            // Check if payload has form fields using the same method
            $hasFormFields = $this->hasFormFields($payload);
            
            // If we found form fields, use payload directly
            // OR if response key exists but is empty, also try payload directly
            if ($hasFormFields || (isset($payload['response']) && empty($payload['response']))) {
                // Get form field keys for logging
                $formFieldKeys = [];
                $metadataKeys = ['form_id', 'entry_id', 'email', 'created_at', 'website_id', 'id', 'status', 'formId', 'entryId', 'createdAt', 'websiteId'];
                foreach (array_keys($payload) as $key) {
                    if (!in_array(strtolower($key), array_map('strtolower', $metadataKeys))) {
                        $formFieldKeys[] = $key;
                    }
                }
                
                Log::info('Using payload directly as response', [
                    'submission_id' => $submissionId,
                    'all_keys' => array_keys($payload),
                    'form_field_keys' => $formFieldKeys,
                    'has_form_fields' => $hasFormFields,
                    'response_empty' => isset($payload['response']) && empty($payload['response']),
                ]);
                return $payload;
            }
        }

        // Log all available top-level keys for debugging
        $payloadKeys = array_keys($payload);
        Log::error('Could not find form response in payload', [
            'submission_id' => $submissionId,
            'payload_keys' => $payloadKeys,
            'payload_keys_count' => count($payloadKeys),
            'payload_structure' => $this->getPayloadStructure($payload),
            'tried_paths' => array_map(fn($path) => implode(' -> ', $path), $possiblePaths),
        ]);

        // Throw exception with helpful information
        throw new \RuntimeException(
            'Cannot find form response in payload. ' .
            'Available top-level keys: ' . implode(', ', $payloadKeys) . '. ' .
            'Tried paths: ' . implode(', ', array_map(fn($path) => implode(' -> ', $path), array_slice($possiblePaths, 0, 3))) . '...'
        );
    }

    /**
     * Check if an array has form fields (not just metadata)
     */
    private function hasFormFields(array $data): bool
    {
        if (empty($data) || !is_array($data)) {
            return false;
        }
        
        $metadataKeys = ['form_id', 'entry_id', 'email', 'created_at', 'website_id', 'id', 'status', 'formId', 'entryId', 'createdAt', 'websiteId'];
        $formFieldIndicators = ['flight', 'from', 'to', 'departure', 'arrival', 'passenger', 'name', 'phone', 'title'];
        
        foreach (array_keys($data) as $key) {
            // Skip metadata keys
            if (in_array(strtolower($key), array_map('strtolower', $metadataKeys))) {
                continue;
            }
            
            $keyLower = strtolower($key);
            // Check if key indicates a form field
            foreach ($formFieldIndicators as $indicator) {
                if (strpos($keyLower, $indicator) !== false) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Get payload structure for logging (limited depth)
     */
    private function getPayloadStructure(array $payload, int $depth = 2): array
    {
        if ($depth <= 0) {
            return ['...'];
        }

        $structure = [];
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $structure[$key] = [
                    'type' => 'array',
                    'keys' => array_slice(array_keys($value), 0, 10),
                    'count' => count($value),
                    'nested' => $this->getPayloadStructure($value, $depth - 1),
                ];
            } else {
                $structure[$key] = [
                    'type' => gettype($value),
                    'preview' => is_string($value) ? substr($value, 0, 50) : $value,
                ];
            }
        }

        return $structure;
    }

    /**
     * Extract flight_json_data from response
     */
    private function extractFlightJsonData(array $response): ?array
    {
        // Look for flight_json_data in various possible locations
        foreach ($response as $key => $value) {
            $keyLower = strtolower($key);
            if (strpos($keyLower, 'flight') !== false && strpos($keyLower, 'json') !== false) {
                $flightData = $value;
                
                // Handle JSON string
                if (is_string($flightData)) {
                    try {
                        $flightData = json_decode($flightData, true);
                    } catch (\Exception $e) {
                        continue;
                    }
                }
                
                if (is_array($flightData) && $this->isValidFlightData($flightData)) {
                    return $flightData;
                }
            }
        }

        return null;
    }

    /**
     * Check if flight data is valid (has itineraries or validatingAirlineCodes)
     */
    private function isValidFlightData(array $data): bool
    {
        $hasItineraries = !empty($data['itineraries']) && is_array($data['itineraries']);
        $hasValidatingAirlines = !empty($data['validatingAirlineCodes']) && is_array($data['validatingAirlineCodes']);
        
        return $hasItineraries || $hasValidatingAirlines;
    }

    /**
     * CASE A: Create PNR directly from flight_json_data (with fallback to search)
     */
    private function createPnrFromFlightData(array $flightData, FfSubmission $submission, array $response): array
    {
        try {
            // Extract passengers from response (normalized format)
            $passengersData = $this->extractPassengersWithContact($submission, $response);

            // Use the first flight offer from flight_json_data
            // Note: Amadeus API expects a flight offer object, not the full flight data
            // We'll need to convert the flight data to a flight offer format
            $flightOffer = $this->convertFlightDataToOffer($flightData);

            // Validate flight offer before sending to Amadeus
            $this->validateFlightOffer($flightOffer);

            // Try to create PNR with the provided offer
            $maxAttempts = 10;
            $lastError = null;
            
            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                try {
                    Log::info('Attempting PNR creation', [
                        'submission_id' => $submission->id,
                        'attempt' => $attempt + 1,
                        'flight_offer_id' => $flightOffer['id'] ?? 'MISSING',
                    ]);

                    // Create PNR
                    $result = $this->amadeusService->createPnr($flightOffer, $passengersData['passengers']);

                    // Extract route and dates from flight data
                    $route = $this->extractRouteFromFlightData($flightData);
                    $dates = $this->extractDatesFromFlightData($flightData);

                    return [
                        'pnr' => $result['pnr'],
                        'pnr_data' => $result['data'] ?? [],
                        'source' => 'amadeus_direct',
                        'used_offer' => $flightOffer,
                        'extracted_data' => [
                            'passengers' => $passengersData['normalized_passengers'],
                            'route' => $route,
                            'dates' => $dates,
                            'email' => $passengersData['email'],
                            'phone' => $passengersData['phone'],
                        ],
                    ];
                } catch (\Exception $e) {
                    $lastError = $e;
                    Log::warning('PNR creation attempt failed', [
                        'submission_id' => $submission->id,
                        'attempt' => $attempt + 1,
                        'error' => $e->getMessage(),
                    ]);

                    // If this was the first attempt and we have flight_json_data, try searching for alternatives
                    if ($attempt === 0) {
                        // Extract search criteria from flight data
                        $searchParams = $this->extractSearchParamsFromFlightData($flightData, $response);
                        
                        if (!empty($searchParams)) {
                            Log::info('Searching for alternative flight offers', [
                                'submission_id' => $submission->id,
                                'search_params' => $searchParams,
                            ]);

                            $alternativeOffers = $this->amadeusService->searchFlights($searchParams);
                            
                            if (!empty($alternativeOffers)) {
                                // Try each alternative offer
                                foreach ($alternativeOffers as $index => $altOffer) {
                                    if ($index >= $maxAttempts - 1) {
                                        break; // Don't exceed max attempts
                                    }
                                    
                                    try {
                                        $this->validateFlightOffer($altOffer);
                                        $result = $this->amadeusService->createPnr($altOffer, $passengersData['passengers']);
                                        
                                        // Success! Extract route and dates
                                        $route = $this->extractRouteFromFlightData($altOffer);
                                        $dates = $this->extractDatesFromFlightData($altOffer);

                                        return [
                                            'pnr' => $result['pnr'],
                                            'pnr_data' => $result['data'] ?? [],
                                            'source' => 'amadeus_search_fallback',
                                            'used_offer' => $altOffer,
                                            'extracted_data' => [
                                                'passengers' => $passengersData['normalized_passengers'],
                                                'route' => $route,
                                                'dates' => $dates,
                                                'email' => $passengersData['email'],
                                                'phone' => $passengersData['phone'],
                                            ],
                                        ];
                                    } catch (\Exception $altError) {
                                        Log::warning('Alternative offer failed', [
                                            'submission_id' => $submission->id,
                                            'offer_index' => $index,
                                            'error' => $altError->getMessage(),
                                        ]);
                                        continue; // Try next offer
                                    }
                                }
                            }
                        }
                    }
                }
            }

            // All attempts failed
            throw new \RuntimeException(
                'Failed to create PNR after ' . $maxAttempts . ' attempts. Last error: ' . 
                ($lastError ? $lastError->getMessage() : 'Unknown error')
            );
        } catch (\Exception $e) {
            Log::error('Failed to create PNR from flight data', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Failed to create PNR from flight data: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * CASE B: Search for flights and create PNR from form fields
     */
    private function createPnrFromFormFields(FfSubmission $submission, array $response): array
    {
        try {
            // LOG WHAT WE RECEIVED
            Log::info('createPnrFromFormFields called', [
                'submission_id' => $submission->id,
                'response_type' => gettype($response),
                'response_is_array' => is_array($response),
                'response_empty' => empty($response),
                'response_keys' => is_array($response) ? array_keys($response) : 'NOT_ARRAY',
                'response_count' => is_array($response) ? count($response) : 0,
                'has_flight_from' => isset($response['flight_from']),
                'has_flight_to' => isset($response['flight_to']),
                'has_flight_departure' => isset($response['flight_departure']),
            ]);

            // Never validate against an empty response
            if (empty($response) || !is_array($response)) {
                Log::error('Empty or invalid response for PNR generation', [
                    'submission_id' => $submission->id,
                    'response_type' => gettype($response),
                    'response_empty' => empty($response),
                ]);
                
                throw new \RuntimeException(
                    'Cannot generate PNR: No form data found in submission. ' .
                    'Please ensure the submission contains flight information (flight_from, flight_to, flight_departure).'
                );
            }

            // Extract flight information from form fields
            $flightParams = $this->extractFlightParams($response);
            
            // If we don't have all required fields, try extracting from entire payload as fallback
            if (empty($flightParams['originLocationCode']) || 
                empty($flightParams['destinationLocationCode']) || 
                empty($flightParams['departureDate'])) {
                $payload = $submission->payload ?? [];
                if ($payload !== $response && !empty($payload) && is_array($payload)) {
                    Log::info('Trying fallback extraction from full payload', [
                        'submission_id' => $submission->id,
                        'payload_keys' => array_keys($payload),
                    ]);
                    
                    $additionalParams = $this->extractFlightParams($payload);
                    // Merge, keeping existing values
                    foreach ($additionalParams as $key => $value) {
                        if (empty($flightParams[$key]) && !empty($value)) {
                            $flightParams[$key] = $value;
                            Log::info('Found missing field in payload fallback', [
                                'field' => $key,
                                'value' => $value,
                            ]);
                        }
                    }
                }
            }
            
            // Validate required fields
            $missingFields = [];
            if (empty($flightParams['originLocationCode'])) {
                $missingFields[] = 'origin airport code (flight_from)';
            } else if (!$this->validateIataCode($flightParams['originLocationCode'])) {
                throw new \RuntimeException(
                    "Invalid origin airport code: {$flightParams['originLocationCode']}. Must be 3 uppercase letters (e.g., JFK, LAX)."
                );
            }
            
            if (empty($flightParams['destinationLocationCode'])) {
                $missingFields[] = 'destination airport code (flight_to)';
            } else if (!$this->validateIataCode($flightParams['destinationLocationCode'])) {
                throw new \RuntimeException(
                    "Invalid destination airport code: {$flightParams['destinationLocationCode']}. Must be 3 uppercase letters (e.g., JFK, LAX)."
                );
            }
            
            if (empty($flightParams['departureDate'])) {
                $missingFields[] = 'departure date (flight_departure)';
            }
            
            if (!empty($missingFields)) {
                $availableKeys = array_keys($response);
                Log::warning('Missing flight information', [
                    'submission_id' => $submission->id,
                    'missing_fields' => $missingFields,
                    'available_keys' => $availableKeys,
                    'extracted_params' => $flightParams,
                ]);
                
                throw new \RuntimeException(
                    'Missing required flight information: ' . implode(', ', $missingFields) . 
                    '. Available form fields: ' . implode(', ', array_slice($availableKeys, 0, 20))
                );
            }

            // Validate departure date
            $flightParams['departureDate'] = $this->validateDate(
                $flightParams['departureDate'], 
                'departure date'
            );

            // Validate return date if present
            if (!empty($flightParams['returnDate'])) {
                $flightParams['returnDate'] = $this->validateDate(
                    $flightParams['returnDate'], 
                    'return date'
                );
            }

            Log::info('Searching for flights', [
                'submission_id' => $submission->id,
                'params' => $flightParams,
            ]);

            // Search for flights
            $flightOffers = $this->amadeusService->searchFlights($flightParams);

            if (empty($flightOffers)) {
                throw new \RuntimeException('No flight offers found for the given criteria. Please check the route and dates.');
            }

            Log::info('Found flight offers', [
                'submission_id' => $submission->id,
                'offers_count' => count($flightOffers),
            ]);

            // Extract passengers with contact info (normalized format)
            $passengersData = $this->extractPassengersWithContact($submission, $response);

            // Try offers in order until one succeeds
            $maxAttempts = min(10, count($flightOffers));
            $lastError = null;

            for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
                $selectedOffer = $flightOffers[$attempt];

                try {
                    // Validate flight offer before sending to Amadeus
                    $this->validateFlightOffer($selectedOffer);

                    Log::info('Attempting PNR creation with offer', [
                        'submission_id' => $submission->id,
                        'attempt' => $attempt + 1,
                        'flight_offer_id' => $selectedOffer['id'] ?? 'MISSING',
                    ]);

                    // Create PNR
                    $result = $this->amadeusService->createPnr($selectedOffer, $passengersData['passengers']);

                    return [
                        'pnr' => $result['pnr'],
                        'pnr_data' => $result['data'] ?? [],
                        'source' => 'amadeus_search',
                        'used_offer' => $selectedOffer,
                        'extracted_data' => [
                            'passengers' => $passengersData['normalized_passengers'],
                            'route' => [
                                'origin' => $flightParams['originLocationCode'],
                                'destination' => $flightParams['destinationLocationCode'],
                            ],
                            'dates' => [
                                'departure' => $flightParams['departureDate'],
                                'return' => $flightParams['returnDate'] ?? null,
                            ],
                            'email' => $passengersData['email'],
                            'phone' => $passengersData['phone'],
                        ],
                    ];
                } catch (\Exception $e) {
                    $lastError = $e;
                    Log::warning('PNR creation attempt failed', [
                        'submission_id' => $submission->id,
                        'attempt' => $attempt + 1,
                        'flight_offer_id' => $selectedOffer['id'] ?? 'MISSING',
                        'error' => $e->getMessage(),
                    ]);
                    // Continue to next offer
                }
            }

            // All attempts failed
            throw new \RuntimeException(
                'Failed to create PNR after trying ' . $maxAttempts . ' offers. Last error: ' . 
                ($lastError ? $lastError->getMessage() : 'Unknown error')
            );
        } catch (\Exception $e) {
            Log::error('Failed to create PNR from form fields', [
                'submission_id' => $submission->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw new \RuntimeException('Failed to create PNR from form fields: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Extract flight parameters from form fields
     */
    private function extractFlightParams(array $response): array
    {
        $params = [
            'originLocationCode' => null,
            'destinationLocationCode' => null,
            'departureDate' => null,
            'returnDate' => null,
            'adults' => 1,
        ];

        // Log all available keys for debugging
        Log::info('Extracting flight params from response', [
            'available_keys' => array_keys($response),
        ]);

        foreach ($response as $key => $value) {
            $keyLower = strtolower(str_replace(['_', '-', ' '], '', $key));
            $originalValue = $value;
            
            // Handle array/object values - extract string value
            if (is_array($value)) {
                // If it's an array, try to get the first string value
                $value = $this->extractStringFromArray($value);
            } elseif (is_object($value)) {
                // If it's an object, try to convert to string
                $value = (string) $value;
            }
            
            // Skip if value is still not a string or is empty
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            
            // Flight from (origin) - more flexible matching
            if (empty($params['originLocationCode'])) {
                if (strpos($keyLower, 'flightfrom') !== false || 
                    strpos($keyLower, 'from') !== false ||
                    strpos($keyLower, 'origin') !== false ||
                    strpos($keyLower, 'departurecity') !== false ||
                    strpos($keyLower, 'departurecitycode') !== false) {
                    $extracted = $this->extractIataCode($value);
                    if ($extracted) {
                        $params['originLocationCode'] = $extracted;
                        Log::info('Found origin', ['key' => $key, 'value' => $value, 'extracted' => $extracted]);
                    }
                }
            }
            
            // Flight to (destination) - more flexible matching
            if (empty($params['destinationLocationCode'])) {
                if (strpos($keyLower, 'flightto') !== false || 
                    (strpos($keyLower, 'to') !== false && strpos($keyLower, 'from') === false) ||
                    strpos($keyLower, 'destination') !== false ||
                    strpos($keyLower, 'arrivalcity') !== false ||
                    strpos($keyLower, 'arrivalcitycode') !== false) {
                    $extracted = $this->extractIataCode($value);
                    if ($extracted) {
                        $params['destinationLocationCode'] = $extracted;
                        Log::info('Found destination', ['key' => $key, 'value' => $value, 'extracted' => $extracted]);
                    }
                }
            }
            
            // Departure date - more flexible matching
            if (empty($params['departureDate'])) {
                if (strpos($keyLower, 'flightdeparture') !== false || 
                    strpos($keyLower, 'departuredate') !== false ||
                    strpos($keyLower, 'departure') !== false ||
                    strpos($keyLower, 'traveldate') !== false ||
                    strpos($keyLower, 'outbounddate') !== false) {
                    $extracted = $this->formatDate($value);
                    if ($extracted) {
                        $params['departureDate'] = $extracted;
                        Log::info('Found departure date', ['key' => $key, 'value' => $value, 'extracted' => $extracted]);
                    }
                }
            }
            
            // Arrival/return date - more flexible matching
            if (empty($params['returnDate'])) {
                if (strpos($keyLower, 'flightarrival') !== false || 
                    strpos($keyLower, 'arrivaldate') !== false ||
                    strpos($keyLower, 'returndate') !== false ||
                    strpos($keyLower, 'inbounddate') !== false) {
                    $extracted = $this->formatDate($value);
                    if ($extracted) {
                        $params['returnDate'] = $extracted;
                        Log::info('Found return date', ['key' => $key, 'value' => $value, 'extracted' => $extracted]);
                    }
                }
            }
        }

        // Log what we found
        Log::info('Extracted flight params', [
            'params' => $params,
            'has_origin' => !empty($params['originLocationCode']),
            'has_destination' => !empty($params['destinationLocationCode']),
            'has_departure' => !empty($params['departureDate']),
        ]);

        return array_filter($params, fn($value) => $value !== null);
    }

    /**
     * Extract string value from array
     */
    private function extractStringFromArray($value): ?string
    {
        if (!is_array($value)) {
            return is_string($value) ? $value : null;
        }

        // Try to find first string value
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                return $item;
            }
            if (is_array($item)) {
                $nested = $this->extractStringFromArray($item);
                if ($nested) {
                    return $nested;
                }
            }
        }

        return null;
    }

    /**
     * Extract IATA code from value (handles both codes and full names)
     */
    private function extractIataCode($value): ?string
    {
        if (is_array($value)) {
            $value = $this->extractStringFromArray($value);
        }
        
        if (!is_string($value)) {
            return null;
        }
        
        $value = trim($value);
        
        // PRIORITY 1: Extract code from parentheses (most common format)
        // Example: "Madrid - Barajas Airport (MAD)" -> "MAD"
        if (preg_match('/\(([A-Z]{3})\)/', $value, $matches)) {
            return $matches[1];
        }
        
        // PRIORITY 2: If it's already a 3-letter IATA code
        $valueUpper = strtoupper($value);
        if (strlen($valueUpper) === 3 && ctype_alpha($valueUpper)) {
            return $valueUpper;
        }
        
        // PRIORITY 3: Try to extract 3-letter code (uppercase letters only) with word boundaries
        if (preg_match('/\b([A-Z]{3})\b/', strtoupper($value), $matches)) {
            return $matches[0];
        }
        
        // PRIORITY 4: Try to extract any 3 consecutive uppercase letters
        if (preg_match('/[A-Z]{3}/', strtoupper($value), $matches)) {
            return $matches[0];
        }
        
        // PRIORITY 5: If value is very short, might be a code
        if (strlen($valueUpper) <= 5 && ctype_alnum($valueUpper)) {
            // Take first 3 uppercase letters
            $code = preg_replace('/[^A-Z]/', '', $valueUpper);
            if (strlen($code) >= 3) {
                return substr($code, 0, 3);
            }
        }
        
        return null;
    }

    /**
     * Format date to YYYY-MM-DD
     */
    private function formatDate($value): ?string
    {
        if (is_array($value)) {
            $value = $this->extractStringFromArray($value);
        }
        
        if (empty($value) || !is_string($value)) {
            return null;
        }

        $value = trim($value);

        // Try various date formats
        $formats = [
            'Y-m-d',
            'Y/m/d',
            'd/m/Y',      // DD/MM/YYYY (European format) - PRIORITY for this case
            'd-m-Y',
            'm-d-Y',
            'm/d/Y',
            'Y-m-d H:i:s',
            'Y-m-d\TH:i:s',
            'Y-m-d\TH:i:s\Z',
        ];

        foreach ($formats as $format) {
            try {
                $date = \DateTime::createFromFormat($format, $value);
                if ($date !== false) {
                    return $date->format('Y-m-d');
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        // Try standard DateTime parsing
        try {
            $date = new \DateTime($value);
            return $date->format('Y-m-d');
        } catch (\Exception $e) {
            // Try strtotime as fallback
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('Y-m-d', $timestamp);
            }
        }

        return null;
    }

    /**
     * Extract passengers from submission with contact information
     * Returns normalized format: [['title','first_name','last_name','email','phone'], ...] (max 9)
     * Also returns Amadeus format for API calls
     */
    private function extractPassengersWithContact(FfSubmission $submission, array $response): array
    {
        $normalizedPassengers = [];
        $amadeusPassengers = [];
        $email = $submission->email;
        $phone = null;

        // Extract contact info
        foreach ($response as $key => $value) {
            $keyLower = strtolower($key);
            if (strpos($keyLower, 'email') !== false && empty($email)) {
                if (is_string($value) && filter_var($value, FILTER_VALIDATE_EMAIL)) {
                    $email = $value;
                }
            }
            if (strpos($keyLower, 'phone') !== false || strpos($keyLower, 'telephone') !== false || strpos($keyLower, 'mobile') !== false) {
                if (is_string($value) && !empty(trim($value))) {
                    $phone = trim($value);
                }
            }
        }

        // Extract passenger objects
        foreach ($response as $key => $value) {
            if (!is_array($value)) {
                continue;
            }

            // Check if this is a passenger object
            $hasFirstName = isset($value['first_name']) || isset($value['firstname']);
            $hasLastName = isset($value['last_name']) || isset($value['lastname']);

            if ($hasFirstName || $hasLastName) {
                $firstName = strtoupper(trim($value['first_name'] ?? $value['firstname'] ?? ''));
                $lastName = strtoupper(trim($value['last_name'] ?? $value['lastname'] ?? ''));
                $title = strtoupper(trim($value['title'] ?? $value['salutation'] ?? 'MR'));
                
                // Validate title
                if (!in_array($title, ['MR', 'MS', 'MRS', 'CHD'])) {
                    $title = 'MR';
                }

                if (!empty($firstName) && !empty($lastName)) {
                    // Stop at 9 passengers
                    if (count($normalizedPassengers) >= 9) {
                        break;
                    }

                    // Normalized format for PDF generation
                    $normalizedPassengers[] = [
                        'title' => $title,
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $email ?? '',
                        'phone' => $phone ?? '',
                    ];

                    // Amadeus format for API calls
                    $amadeusPassengers[] = [
                        'firstName' => $firstName,
                        'lastName' => $lastName,
                        'dateOfBirth' => $value['date_of_birth'] ?? $value['dateOfBirth'] ?? null,
                        'contact' => [
                            'emailAddress' => $email ?? '',
                            'phones' => $phone ? [[
                                'deviceType' => 'MOBILE',
                                'countryCallingCode' => '1',
                                'number' => preg_replace('/[^0-9]/', '', $phone),
                            ]] : [],
                        ],
                    ];
                }
            }
        }

        // If no passengers found, create a default one
        if (empty($normalizedPassengers)) {
            $normalizedPassengers[] = [
                'title' => 'MR',
                'first_name' => 'PASSENGER',
                'last_name' => 'TEST',
                'email' => $email ?? 'test@example.com',
                'phone' => $phone ?? '',
            ];
            
            $amadeusPassengers[] = [
                'firstName' => 'PASSENGER',
                'lastName' => 'TEST',
                'dateOfBirth' => null,
                'contact' => [
                    'emailAddress' => $email ?? 'test@example.com',
                    'phones' => $phone ? [[
                        'deviceType' => 'MOBILE',
                        'countryCallingCode' => '1',
                        'number' => preg_replace('/[^0-9]/', '', $phone),
                    ]] : [],
                ],
            ];
        }

        return [
            'passengers' => $amadeusPassengers, // For Amadeus API
            'normalized_passengers' => $normalizedPassengers, // For PDF generation
            'email' => $email,
            'phone' => $phone,
        ];
    }

    /**
     * Convert flight data to Amadeus flight offer format
     * This is a simplified conversion - in production, you'd need to map all fields properly
     * REQUIREMENT: Do NOT overwrite ID - validate and use as provided
     */
    private function convertFlightDataToOffer(array $flightData): array
    {
        // ========================================
        // CRITICAL: Do NOT overwrite flightOffer['id']
        // ========================================
        // REQUIREMENT: Validate it as string and acceptable pattern; use it as provided
        // Only generate a new ID if the provided one is invalid or missing
        
        $originalId = $flightData['id'] ?? null;
        
        // Validate the provided ID
        if (empty($originalId)) {
            // If ID is missing, generate one
            $newId = $this->generateCleanOfferId();
            Log::warning('convertFlightDataToOffer: ID was missing, generated new ID', [
                'generated_id' => $newId,
            ]);
        } elseif (!is_string($originalId)) {
            // If ID is not a string, convert it
            $newId = (string)$originalId;
            Log::warning('convertFlightDataToOffer: ID was not a string, converted to string', [
                'original_id' => $originalId,
                'original_type' => gettype($originalId),
                'converted_id' => $newId,
            ]);
        } elseif (!preg_match('/^[A-Za-z0-9_-]+$/', $originalId)) {
            // If ID contains invalid characters, generate a new one
            $newId = $this->generateCleanOfferId();
            Log::warning('convertFlightDataToOffer: ID contained invalid characters, generated new ID', [
                'original_id' => $originalId,
                'generated_id' => $newId,
            ]);
        } else {
            // ID is valid - use it as provided
            $newId = $originalId;
            Log::info('convertFlightDataToOffer: Using provided ID', [
                'id' => $newId,
            ]);
        }
        
        // For now, return the flight data as-is if it's already in offer format
        // Otherwise, we'll need to construct a minimal offer
        if (isset($flightData['type']) && $flightData['type'] === 'flight-offer') {
            // Make a deep copy of the array (not a reference) to avoid modifying original
            // Use json_encode/decode to ensure we get a completely new array
            $offer = json_decode(json_encode($flightData), true);
            
            // Set ID (validated above)
            $offer['id'] = $newId;
            
            Log::info('Flight offer converted with validated ID', [
                'original_id' => $originalId,
                'final_id' => $offer['id'],
            ]);

            return $offer;
        }

        // Construct minimal offer from flight data
        // This is a fallback - ideally flight_json_data should already be in offer format
        // Use validated ID
        $offer = [
            'type' => 'flight-offer',
            'id' => $newId,
            'source' => $flightData['source'] ?? 'GDS',
            'instantTicketingRequired' => false,
            'nonHomogeneous' => false,
            'oneWay' => empty($flightData['itineraries']) || count($flightData['itineraries']) === 1,
            'lastTicketingDate' => $flightData['lastTicketingDate'] ?? date('Y-m-d', strtotime('+30 days')),
            'numberOfBookableSeats' => $flightData['numberOfBookableSeats'] ?? 9,
            'itineraries' => $flightData['itineraries'] ?? [],
            'price' => $flightData['price'] ?? ['total' => '0.00', 'currency' => 'USD'],
            'validatingAirlineCodes' => $flightData['validatingAirlineCodes'] ?? [],
            'travelerPricings' => $flightData['travelerPricings'] ?? [],
        ];

        // Only include pricingOptions if it exists and is a valid object (not empty array)
        // Amadeus API rejects empty arrays for pricingOptions
        if (isset($flightData['pricingOptions']) && 
            is_array($flightData['pricingOptions']) && 
            !empty($flightData['pricingOptions'])) {
            $offer['pricingOptions'] = $flightData['pricingOptions'];
        }

        return $offer;
    }

    /**
     * Generate a clean alphanumeric offer ID
     * Format: 'FO_' + strtoupper(random alphanumeric string)
     * Example: FO_A92KX1QZ
     * Amadeus API requires IDs to be alphanumeric (letters, numbers, underscore allowed)
     */
    private function generateCleanOfferId(): string
    {
        // Generate ID for Amadeus
        // CRITICAL: Amadeus expects flight offer IDs to be NUMERIC STRINGS (e.g., "1", "2", "123")
        // The error example "1" suggests simple numeric IDs are preferred
        // Format: Simple numeric string starting from "1"
        // Use a simple counter-based approach - start with "1" for first offer
        static $offerCounter = 0;
        $offerCounter++;
        return (string)$offerCounter; // Simple numeric: "1", "2", "3", etc.
    }

    /**
     * Check if a flight offer ID is valid according to Amadeus requirements
     * Valid: string, matches /^[A-Za-z0-9_-]+$/, contains at least one letter
     * 
     * Note: Amadeus search API returns numeric IDs (e.g., "1", "2"), but booking API
     * requires alphanumeric IDs with at least one letter. We accept both formats here
     * and fix numeric IDs before sending to the booking API.
     * 
     * @param mixed $id The ID to validate
     * @return bool True if valid, false otherwise
     */
    private function isValidFlightOfferId($id): bool
    {
        // Must be a string
        if (!is_string($id)) {
            return false;
        }
        
        // Must not be empty
        if (trim($id) === '') {
            return false;
        }
        
        // Must match alphanumeric pattern (letters, numbers, underscore, hyphen)
        if (!preg_match('/^[A-Za-z0-9_-]+$/', $id)) {
            return false;
        }
        
        // CRITICAL: Amadeus booking API rejects purely numeric IDs (e.g., "1", "123")
        // Must contain at least one letter (alphabetic character)
        if (!preg_match('/[A-Za-z]/', $id)) {
            return false;
        }
        
        return true;
    }

    /**
     * Validate flight offer before sending to Amadeus API
     * Removes invalid fields that would cause API errors
     * REQUIREMENT: Do NOT overwrite ID - validate and use as provided
     */
    private function validateFlightOffer(array &$flightOffer): void
    {
        // ========================================
        // CRITICAL: Do NOT overwrite flightOffer['id']
        // ========================================
        // REQUIREMENT: Validate it as string and acceptable pattern; use it as provided
        // Only generate a new ID if the provided one is invalid or missing
        
        $originalId = $flightOffer['id'] ?? null;
        
        // Validate the provided ID
        if (empty($originalId)) {
            // If ID is missing, generate one
            $flightOffer['id'] = $this->generateCleanOfferId();
            Log::warning('validateFlightOffer: ID was missing, generated new ID', [
                'generated_id' => $flightOffer['id'],
            ]);
        } elseif (!is_string($originalId)) {
            // If ID is not a string, convert it
            $flightOffer['id'] = (string)$originalId;
            Log::warning('validateFlightOffer: ID was not a string, converted to string', [
                'original_id' => $originalId,
                'original_type' => gettype($originalId),
                'converted_id' => $flightOffer['id'],
            ]);
        } elseif (!preg_match('/^[A-Za-z0-9_-]+$/', $originalId)) {
            // If ID contains invalid characters, generate a new one
            $flightOffer['id'] = $this->generateCleanOfferId();
            Log::warning('validateFlightOffer: ID contained invalid characters, generated new ID', [
                'original_id' => $originalId,
                'generated_id' => $flightOffer['id'],
            ]);
        } else {
            // ID is valid - use it as provided
            $flightOffer['id'] = $originalId;
            Log::info('validateFlightOffer: Using provided ID', [
                'id' => $flightOffer['id'],
            ]);
        }

        // Remove pricingOptions if it's an empty array (Amadeus rejects empty arrays)
        if (isset($flightOffer['pricingOptions']) && 
            (empty($flightOffer['pricingOptions']) || !is_array($flightOffer['pricingOptions']))) {
            unset($flightOffer['pricingOptions']);
        }

        // Ensure required fields are present
        if (empty($flightOffer['itineraries']) || !is_array($flightOffer['itineraries'])) {
            throw new \RuntimeException('Flight offer must have at least one itinerary');
        }

        if (empty($flightOffer['price']) || !is_array($flightOffer['price'])) {
            throw new \RuntimeException('Flight offer must have a price object');
        }

        // Log the cleaned offer for debugging
        Log::info('Validated flight offer for PNR creation', [
            'id' => $flightOffer['id'],
            'has_pricingOptions' => isset($flightOffer['pricingOptions']),
            'itineraries_count' => count($flightOffer['itineraries'] ?? []),
            'has_price' => !empty($flightOffer['price']),
        ]);
    }

    /**
     * Validate IATA airport code
     * IATA codes are 3 uppercase letters
     */
    private function validateIataCode(?string $code): bool
    {
        if (empty($code)) {
            return false;
        }
        
        // Must be exactly 3 uppercase letters
        return preg_match('/^[A-Z]{3}$/', trim($code)) === 1;
    }

    /**
     * Validate and format date
     * Returns formatted date (YYYY-MM-DD) or throws exception
     */
    private function validateDate(?string $date, string $fieldName): string
    {
        if (empty($date)) {
            throw new \RuntimeException("Missing required field: {$fieldName}");
        }

        $formattedDate = $this->formatDate($date);
        
        if (!$formattedDate) {
            throw new \RuntimeException("Invalid date format for {$fieldName}: {$date}. Expected format: YYYY-MM-DD or similar.");
        }

        // Check if date is not in the past (for departure dates)
        if (strpos($fieldName, 'departure') !== false) {
            $dateObj = new \DateTime($formattedDate);
            $today = new \DateTime('today');
            
            if ($dateObj < $today) {
                Log::warning("Departure date is in the past", [
                    'field' => $fieldName,
                    'date' => $formattedDate,
                ]);
                // Don't throw error, just log warning - might be historical data
            }
        }

        return $formattedDate;
    }

    /**
     * Extract route information from flight data
     */
    private function extractRouteFromFlightData(?array $flightData): array
    {
        $route = ['origin' => null, 'destination' => null];
        
        if (!$flightData || !is_array($flightData)) {
            return $route;
        }

        // Try to extract from itineraries
        if (!empty($flightData['itineraries']) && is_array($flightData['itineraries'])) {
            $firstItinerary = $flightData['itineraries'][0];
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
     * Extract dates from flight data
     */
    private function extractDatesFromFlightData(?array $flightData): array
    {
        $dates = ['departure' => null, 'return' => null];
        
        if (!$flightData || !is_array($flightData)) {
            return $dates;
        }

        // Try to extract from itineraries
        if (!empty($flightData['itineraries']) && is_array($flightData['itineraries'])) {
            // Departure date from first itinerary
            if (!empty($flightData['itineraries'][0])) {
                $firstItinerary = $flightData['itineraries'][0];
                $segments = $firstItinerary['segments'] ?? [];
                
                if (!empty($segments) && !empty($segments[0]['departure']['at'])) {
                    try {
                        $date = new \DateTime($segments[0]['departure']['at']);
                        $dates['departure'] = $date->format('Y-m-d');
                    } catch (\Exception $e) {
                        Log::warning('Failed to parse departure date from flight data', [
                            'date_string' => $segments[0]['departure']['at'],
                        ]);
                    }
                }
            }
            
            // Return date from second itinerary (if exists)
            if (!empty($flightData['itineraries'][1])) {
                $returnItinerary = $flightData['itineraries'][1];
                $segments = $returnItinerary['segments'] ?? [];
                
                if (!empty($segments) && !empty($segments[0]['departure']['at'])) {
                    try {
                        $date = new \DateTime($segments[0]['departure']['at']);
                        $dates['return'] = $date->format('Y-m-d');
                    } catch (\Exception $e) {
                        Log::warning('Failed to parse return date from flight data', [
                            'date_string' => $segments[0]['departure']['at'],
                        ]);
                    }
                }
            }
        }

        return $dates;
    }

    /**
     * Extract search parameters from flight data for fallback search
     */
    private function extractSearchParamsFromFlightData(array $flightData, array $response): array
    {
        $params = [
            'originLocationCode' => null,
            'destinationLocationCode' => null,
            'departureDate' => null,
            'returnDate' => null,
            'adults' => 1,
        ];

        // Extract from flight data itineraries
        if (!empty($flightData['itineraries']) && is_array($flightData['itineraries'])) {
            $firstItinerary = $flightData['itineraries'][0];
            $segments = $firstItinerary['segments'] ?? [];
            
            if (!empty($segments)) {
                $firstSegment = $segments[0];
                $params['originLocationCode'] = $firstSegment['departure']['iataCode'] ?? null;
                
                $lastSegment = $segments[count($segments) - 1];
                $params['destinationLocationCode'] = $lastSegment['arrival']['iataCode'] ?? null;
                
                if (!empty($firstSegment['departure']['at'])) {
                    try {
                        $date = new \DateTime($firstSegment['departure']['at']);
                        $params['departureDate'] = $date->format('Y-m-d');
                    } catch (\Exception $e) {
                        // Ignore
                    }
                }
            }

            // Check for return flight
            if (!empty($flightData['itineraries'][1])) {
                $returnItinerary = $flightData['itineraries'][1];
                $returnSegments = $returnItinerary['segments'] ?? [];
                
                if (!empty($returnSegments) && !empty($returnSegments[0]['departure']['at'])) {
                    try {
                        $date = new \DateTime($returnSegments[0]['departure']['at']);
                        $params['returnDate'] = $date->format('Y-m-d');
                    } catch (\Exception $e) {
                        // Ignore
                    }
                }
            }
        }

        // Fallback to form fields if not found in flight data
        if (empty($params['originLocationCode']) || empty($params['destinationLocationCode']) || empty($params['departureDate'])) {
            $formParams = $this->extractFlightParams($response);
            
            if (empty($params['originLocationCode']) && !empty($formParams['originLocationCode'])) {
                $params['originLocationCode'] = $formParams['originLocationCode'];
            }
            if (empty($params['destinationLocationCode']) && !empty($formParams['destinationLocationCode'])) {
                $params['destinationLocationCode'] = $formParams['destinationLocationCode'];
            }
            if (empty($params['departureDate']) && !empty($formParams['departureDate'])) {
                $params['departureDate'] = $formParams['departureDate'];
            }
            if (empty($params['returnDate']) && !empty($formParams['returnDate'])) {
                $params['returnDate'] = $formParams['returnDate'];
            }
        }

        // Extract passenger count
        if (!empty($flightData['travelerPricings']) && is_array($flightData['travelerPricings'])) {
            $params['adults'] = count($flightData['travelerPricings']);
        }

        return array_filter($params, fn($value) => $value !== null);
    }
}

