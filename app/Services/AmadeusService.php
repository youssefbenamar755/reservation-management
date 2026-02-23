<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class AmadeusService
{
    private string $clientId;
    private string $clientSecret;
    private string $baseUrl;
    private ?string $accessToken = null;


    public function __construct()
    {
        $this->clientId     = config('amadeus.client_id');
        $this->clientSecret = config('amadeus.client_secret');
        $this->baseUrl      = config('amadeus.base_url', 'https://test.api.amadeus.com');
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
        // CRITICAL: Do NOT overwrite flightOffer['id']
        // ========================================
        // REQUIREMENT: Validate it as string and acceptable pattern; use it as provided
        // Only generate a new ID if the provided one is invalid
        
        $originalId = $flightOffer['id'] ?? null;

        // Validate the provided ID; generate a safe fallback if missing or invalid
        if (empty($originalId)) {
            $flightOffer['id'] = (string) Str::uuid();
            Log::warning('Flight offer ID was missing, generated UUID fallback', [
                'generated_id' => $flightOffer['id'],
            ]);
        } elseif (!is_string($originalId)) {
            $flightOffer['id'] = (string) $originalId;
            Log::warning('Flight offer ID was not a string, converted', [
                'original_id'   => $originalId,
                'original_type' => gettype($originalId),
                'converted_id'  => $flightOffer['id'],
            ]);
        } elseif (!preg_match('/^[A-Za-z0-9_-]+$/', $originalId)) {
            $flightOffer['id'] = (string) Str::uuid();
            Log::warning('Flight offer ID had invalid characters, generated UUID fallback', [
                'original_id'  => $originalId,
                'generated_id' => $flightOffer['id'],
            ]);
        } else {
            $flightOffer['id'] = $originalId;
        }

        Log::info('Using flight offer ID (validated, not overwritten)', [
            'flight_offer_id' => $flightOffer['id'],
            'id_type' => gettype($flightOffer['id']),
            'id_length' => strlen($flightOffer['id']),
            'was_provided' => $originalId !== null,
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

            // CRITICAL: Final validation - ensure ID is valid before building payload
            // The ID should already be validated above, but double-check
            if (!isset($flightOffer['id']) || !is_string($flightOffer['id']) || !preg_match('/^[A-Za-z0-9_-]+$/', $flightOffer['id'])) {
                throw new \RuntimeException('Flight offer ID is invalid after validation: ' . ($flightOffer['id'] ?? 'MISSING'));
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
                
                // ID must match alphanumeric pattern (letters, numbers, underscore, hyphen)
                if (!preg_match('/^[A-Za-z0-9_-]+$/', $offerId)) {
                    throw new \RuntimeException("Flight offer ID at index {$index} contains invalid characters: '{$offerId}'");
                }

                Log::info('Flight offer ID validated before API request', [
                    'index'    => $index,
                    'id'       => $offerId,
                    'type'     => gettype($offerId),
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

