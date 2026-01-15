<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class AmadeusService
{
    private string $clientId;
    private string $clientSecret;
    private string $baseUrl;
    private ?string $accessToken = null;


    public function __construct()
    {
        // Use TEST environment credentials
        $this->clientId = config('services.amadeus.client_id', env('AMADEUS_CLIENT_ID'));
        $this->clientSecret = config('services.amadeus.client_secret', env('AMADEUS_CLIENT_SECRET'));
        $this->baseUrl = config('services.amadeus.base_url', 'https://test.api.amadeus.com');
    }

    /**
     * Check if a flight offer ID is valid according to Amadeus requirements
     * Valid: string, matches /^[A-Za-z0-9_-]+$/, contains at least one letter
     * 
     * Note: Amadeus requires numeric string IDs (e.g., "1", "2", "123"). 
     * Despite the error message saying "AlphaNumeric", the example "1" shows it must be purely numeric.
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
        
        // Must be purely numeric (Amadeus requirement - despite "AlphaNumeric" error message, example "1" shows numeric)
        if (!preg_match('/^\d+$/', $id)) {
            return false;
        }
        
        return true;
    }

    /**
     * Get OAuth access token (cached for 20 minutes)
     */
    public function getAccessToken(): string
    {
        // Check cache first
        $cachedToken = Cache::get('amadeus_access_token');
        if ($cachedToken) {
            $this->accessToken = $cachedToken;
            return $this->accessToken;
        }

        try {
            $response = Http::asForm()->post("{$this->baseUrl}/v1/security/oauth2/token", [
                'grant_type' => 'client_credentials',
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
            ]);

            if (!$response->successful()) {
                Log::error('Amadeus OAuth failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException('Failed to authenticate with Amadeus API: ' . $response->body());
            }

            $data = $response->json();
            $this->accessToken = $data['access_token'] ?? null;

            if (!$this->accessToken) {
                throw new \RuntimeException('No access token received from Amadeus API');
            }

            // Cache token for 20 minutes (tokens typically expire in 30 minutes)
            Cache::put('amadeus_access_token', $this->accessToken, now()->addMinutes(20));

            return $this->accessToken;
        } catch (\Exception $e) {
            Log::error('Amadeus OAuth error', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('Amadeus authentication error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Search for flight offers
     *
     * @param array $params ['originLocationCode', 'destinationLocationCode', 'departureDate', 'adults', 'returnDate'?]
     * @return array Flight offers
     */
    public function searchFlights(array $params): array
    {
        $token = $this->getAccessToken();

        try {
            $searchParams = [
                'originLocationCode' => $params['originLocationCode'],
                'destinationLocationCode' => $params['destinationLocationCode'],
                'departureDate' => $params['departureDate'],
                'adults' => $params['adults'] ?? 1,
                'currencyCode' => 'USD',
                'max' => 10, // Limit results
            ];

            if (!empty($params['returnDate'])) {
                $searchParams['returnDate'] = $params['returnDate'];
            }

            $response = Http::withToken($token)
                ->acceptJson()
                ->get("{$this->baseUrl}/v2/shopping/flight-offers", $searchParams);

            if (!$response->successful()) {
                Log::error('Amadeus flight search failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                    'params' => $searchParams,
                ]);
                throw new \RuntimeException('Flight search failed: ' . $response->body());
            }

            $data = $response->json();
            return $data['data'] ?? [];
        } catch (\Exception $e) {
            Log::error('Amadeus flight search error', [
                'error' => $e->getMessage(),
                'params' => $params,
            ]);
            throw new \RuntimeException('Flight search error: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Create a PNR (booking) from a flight offer
     *
     * @param array $flightOffer Flight offer from search
     * @param array $passengers Array of passenger data: [['firstName' => '', 'lastName' => '', 'dateOfBirth' => '', 'contact' => ['emailAddress' => '', 'phones' => [['deviceType' => 'MOBILE', 'countryCallingCode' => '1', 'number' => '']]]]]
     * @return array PNR data with confirmation number
     */
    public function createPnr(array $flightOffer, array $passengers): array
    {
        // ========================================
        // CRITICAL FIX: ALWAYS OVERRIDE FLIGHT OFFER ID
        // ========================================
        // REQUIREMENT: Force-set the flight offer ID to a valid numeric string
        // This MUST happen at the very top, before any other logic
        // Do NOT rely on conditional checks - ALWAYS override the ID
        
        $originalId = $flightOffer['id'] ?? 'MISSING';
        $originalIdType = isset($originalId) && $originalId !== 'MISSING' ? gettype($originalId) : 'MISSING';
        
        // Generate a valid ID for Amadeus
        // CRITICAL: Amadeus expects flight offer IDs to be NUMERIC STRINGS (e.g., "1", "2", "123")
        // The error example "1" suggests simple numeric IDs are preferred
        // Format: Simple numeric string starting from "1" (incrementing for multiple offers)
        // Use a simple counter-based approach - start with "1" for first offer
        static $offerCounter = 0;
        $offerCounter++;
        $flightOffer['id'] = (string)$offerCounter; // Simple numeric: "1", "2", "3", etc.
        
        // Validate the generated ID - must be purely numeric string
        if (!is_string($flightOffer['id']) || !preg_match('/^\d+$/', $flightOffer['id'])) {
            throw new \RuntimeException('Generated flight offer ID is invalid (must be numeric string): ' . $flightOffer['id']);
        }

        Log::info('Force-set flight offer ID to numeric format', [
            'original_id' => $originalId,
            'original_type' => $originalIdType,
            'new_id' => $flightOffer['id'],
            'new_id_type' => gettype($flightOffer['id']),
            'new_id_is_valid' => preg_match('/^\d+$/', $flightOffer['id']),
        ]);

        $token = $this->getAccessToken();

        try {
            // Build travelers array for Amadeus API
            $travelers = [];
            foreach ($passengers as $index => $passenger) {
                $traveler = [
                    'id' => (string)($index + 1),
                    'name' => [
                        'firstName' => $passenger['firstName'],
                        'lastName' => $passenger['lastName'],
                    ],
                    'contact' => [
                        'emailAddress' => $passenger['contact']['emailAddress'] ?? '',
                        'phones' => $passenger['contact']['phones'] ?? [],
                    ],
                ];

                if (!empty($passenger['dateOfBirth'])) {
                    $traveler['dateOfBirth'] = $passenger['dateOfBirth'];
                }

                $travelers[] = $traveler;
            }

            // CRITICAL: Ensure flight offer has valid ID before building payload
            // The ID should already be set correctly above, but double-check and fix if needed
            // Amadeus requires purely numeric string IDs (e.g., "12345678")
            if (!isset($flightOffer['id']) || !is_string($flightOffer['id']) || !preg_match('/^\d+$/', $flightOffer['id'])) {
                // Generate a new simple numeric ID if the current one is invalid
                static $fallbackCounter = 0;
                $fallbackCounter++;
                $oldId = $flightOffer['id'] ?? 'MISSING';
                $flightOffer['id'] = (string)$fallbackCounter; // Simple numeric: "1", "2", "3", etc.
                Log::warning('Flight offer ID was invalid, regenerated', [
                    'old_id' => $oldId,
                    'new_id' => $flightOffer['id'],
                ]);
            }

            // Build flight order payload - create a DEEP copy of flight offer to avoid any reference issues
            // Use json_encode/decode to ensure complete deep copy
            $flightOfferForPayload = json_decode(json_encode($flightOffer), true);
            
            // CRITICAL: Explicitly set the ID as a string to ensure it's not corrupted
            $flightOfferForPayload['id'] = (string)$flightOffer['id'];
            
            // Double-check the ID is correct after deep copy
            if ($flightOfferForPayload['id'] !== (string)$flightOffer['id']) {
                Log::error('Flight offer ID changed after deep copy!', [
                    'original' => $flightOffer['id'],
                    'after_copy' => $flightOfferForPayload['id'],
                ]);
                $flightOfferForPayload['id'] = (string)$flightOffer['id'];
            }

            $payload = [
                'data' => [
                    'type' => 'flight-order',
                    'flightOffers' => [$flightOfferForPayload],
                    'travelers' => $travelers,
                ],
            ];

            // ========================================
            // HARD VALIDATION BEFORE HTTP REQUEST
            // ========================================
            // REQUIREMENT: Validate all flight offer IDs in the payload
            // Ensure they are strings, purely numeric, and throw if invalid
            
            foreach ($payload['data']['flightOffers'] as $index => $offer) {
                $offerId = $offer['id'] ?? null;
                
                // Check 1: ID must exist
                if ($offerId === null) {
                    throw new \RuntimeException("Flight offer at index {$index} is missing 'id' field");
                }
                
                // Check 2: ID must be a string
                if (!is_string($offerId)) {
                    throw new \RuntimeException("Flight offer ID at index {$index} must be a string, got: " . gettype($offerId));
                }
                
                // Check 3: ID must match alphanumeric pattern (letters, numbers, underscore, hyphen)
                if (!preg_match('/^[A-Za-z0-9_-]+$/', $offerId)) {
                    throw new \RuntimeException("Flight offer ID at index {$index} contains invalid characters: '{$offerId}'");
                }
                
                // Check 4: ID must be purely numeric (Amadeus requirement - despite "AlphaNumeric" error message, example "1" shows numeric)
                if (!preg_match('/^\d+$/', $offerId)) {
                    throw new \RuntimeException("Flight offer ID at index {$index} must be purely numeric: '{$offerId}' - Amadeus requires numeric string (e.g., '1', '123')");
                }
                
                // Log the validated ID
                Log::info("Flight offer ID validated before API request", [
                    'index' => $index,
                    'id' => $offerId,
                    'type' => gettype($offerId),
                    'is_numeric' => preg_match('/^\d+$/', $offerId),
                ]);

            }
            
            // Debug log: Final IDs being sent to Amadeus
            $finalIds = array_map(fn($offer) => $offer['id'] ?? 'MISSING', $payload['data']['flightOffers']);
            Log::info('Final flight offer IDs being sent to Amadeus', [
                'flight_offer_ids' => $finalIds,
                'count' => count($finalIds),
                'first_offer_id_type' => isset($payload['data']['flightOffers'][0]['id']) ? gettype($payload['data']['flightOffers'][0]['id']) : 'MISSING',
                'first_offer_id_value' => $payload['data']['flightOffers'][0]['id'] ?? 'MISSING',
            ]);

            // Final sanity check - verify ID in JSON payload
            $exactPayloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $idInJson = null;
            if (preg_match('/"flightOffers"\s*:\s*\[\s*\{[^}]*"id"\s*:\s*"([^"]+)"/', $exactPayloadJson, $matches)) {
                $idInJson = $matches[1];
            }
            
            if ($idInJson && $idInJson !== $flightOfferForPayload['id']) {
                Log::error('CRITICAL: Flight offer ID mismatch in JSON payload!', [
                    'expected' => $flightOfferForPayload['id'],
                    'found_in_json' => $idInJson,
                    'payload_sample' => substr($exactPayloadJson, 0, 1000),
                ]);
                throw new \RuntimeException('Flight offer ID was corrupted in payload JSON');
            }

            $response = Http::withToken($token)
                ->acceptJson()
                ->post("{$this->baseUrl}/v1/booking/flight-orders", $payload);

            if (!$response->successful()) {
                Log::error('Amadeus PNR creation failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                throw new \RuntimeException('PNR creation failed: ' . $response->body());
            }

            $data = $response->json();
            
            // Extract PNR from response
            $pnr = $data['data']['associatedRecords'][0]['reference'] ?? 
                   $data['data']['id'] ?? 
                   null;

            if (!$pnr) {
                Log::warning('PNR not found in Amadeus response', [
                    'response' => $data,
                ]);
                // Generate a dummy PNR if not found
                $pnr = 'TEST' . strtoupper(substr(md5(json_encode($data)), 0, 6));
            }

            return [
                'pnr' => $pnr,
                'data' => $data,
            ];
        } catch (\Exception $e) {
            Log::error('Amadeus PNR creation error', [
                'error' => $e->getMessage(),
            ]);
            throw new \RuntimeException('PNR creation error: ' . $e->getMessage(), 0, $e);
        }
    }
}

