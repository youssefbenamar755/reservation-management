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

            // Log the flight offer ID before sending
            Log::info('Sending flight offer to Amadeus for PNR creation', [
                'offer_id' => $flightOffer['id'] ?? 'MISSING',
                'offer_id_type' => isset($flightOffer['id']) ? gettype($flightOffer['id']) : 'N/A',
                'offer_id_is_alnum' => isset($flightOffer['id']) ? ctype_alnum((string)$flightOffer['id']) : false,
            ]);

            // Build flight order payload
            $payload = [
                'data' => [
                    'type' => 'flight-order',
                    'flightOffers' => [$flightOffer],
                    'travelers' => $travelers,
                ],
            ];

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

