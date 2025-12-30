<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class SellingPlatformConnectService
{
    /**
     * Generate an Amadeus-style dummy ticket code from normalized flight data
     * Format: {AIRLINE}{DD}{MMM}{ORIGIN}{DEST}/XIST/ATK
     * Example: SN30NOVSINDLA/XIST/ATK
     *
     * @param array $flightData Normalized flight data structure
     * @return array{code: string, provider: string}
     */
    public function generateAmadeusCode(array $flightData): array
    {
        try {
            // Validate required fields
            if (empty($flightData['origin']) || empty($flightData['destination'])) {
                throw new \InvalidArgumentException('Origin and destination are required to generate Amadeus code');
            }

            if (empty($flightData['departure_date'])) {
                throw new \InvalidArgumentException('Departure date is required to generate Amadeus code');
            }

            // Generate structured code
            $code = $this->generateStructuredCode($flightData);

            Log::info('Amadeus code generated via SellingPlatformConnect', [
                'code' => $code,
                'flight_data' => $this->sanitizeFlightDataForLogging($flightData),
            ]);

            return [
                'code' => $code,
                'provider' => 'sellingplatformconnect',
            ];
        } catch (\Throwable $e) {
            Log::error('Failed to generate Amadeus code', [
                'error' => $e->getMessage(),
                'flight_data' => $this->sanitizeFlightDataForLogging($flightData),
            ]);

            throw new \RuntimeException('Failed to generate Amadeus code: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate structured Amadeus-style code
     * Format: {AIRLINE}{DD}{MMM}{ORIGIN}{DEST}/XIST/ATK
     */
    private function generateStructuredCode(array $flightData): string
    {
        // 1. Get airline code (2 letters)
        $airline = $this->extractAirlineCode($flightData);

        // 2. Format date (DD + MMM uppercase)
        $datePart = $this->formatDatePart($flightData['departure_date']);

        // 3. Get origin IATA code (3 letters)
        $origin = $this->extractIataCode($flightData['origin']);

        // 4. Get destination IATA code (3 letters)
        $destination = $this->extractIataCode($flightData['destination']);

        // 5. Assemble code: AIRLINE + DD + MMM + ORIGIN + DEST + "/XIST/ATK"
        $code = $airline . $datePart . $origin . $destination . '/XIST/ATK';

        return strtoupper($code);
    }

    /**
     * Extract airline code (2 letters)
     * Priority: validatingAirlineCodes[0] > carrierCode > airline_code > "XX"
     */
    private function extractAirlineCode(array $flightData): string
    {
        // Priority 1: validatingAirlineCodes[0] from flight_json_data
        if (!empty($flightData['validating_airline_codes']) && is_array($flightData['validating_airline_codes'])) {
            $code = strtoupper(substr($flightData['validating_airline_codes'][0] ?? '', 0, 2));
            if (strlen($code) === 2 && ctype_alpha($code)) {
                return $code;
            }
        }

        // Priority 2: carrierCode from first segment
        if (!empty($flightData['carrier_code'])) {
            $code = strtoupper(substr($flightData['carrier_code'], 0, 2));
            if (strlen($code) === 2 && ctype_alpha($code)) {
                return $code;
            }
        }

        // Priority 3: Direct airline_code field (fallback)
        if (!empty($flightData['airline_code'])) {
            $code = strtoupper(substr($flightData['airline_code'], 0, 2));
            if (strlen($code) === 2 && ctype_alpha($code)) {
                return $code;
            }
        }

        // Final fallback
        return 'XX';
    }

    /**
     * Format date as DD + MMM (uppercase)
     * Example: "2024-11-30" -> "30NOV"
     */
    private function formatDatePart(string $dateString): string
    {
        try {
            $date = new \DateTime($dateString);
            $day = $date->format('d'); // 2-digit day (01-31)
            $month = strtoupper($date->format('M')); // 3-letter month (JAN, FEB, etc.)
            return $day . $month;
        } catch (\Exception $e) {
            // Try parsing common date formats
            $timestamp = strtotime($dateString);
            if ($timestamp !== false) {
                $day = date('d', $timestamp);
                $month = strtoupper(date('M', $timestamp));
                return $day . $month;
            }

            throw new \InvalidArgumentException('Invalid departure date format: ' . $dateString);
        }
    }

    /**
     * Extract IATA code (3 letters) from origin/destination
     * Handles both IATA codes and full airport names
     */
    private function extractIataCode(string $value): string
    {
        $value = trim(strtoupper($value));

        // If it's already a 3-letter IATA code, return it
        if (strlen($value) === 3 && ctype_alpha($value)) {
            return $value;
        }

        // Try to extract 3-letter code (IATA codes are always 3 uppercase letters)
        // Pattern: any 3 consecutive uppercase letters
        if (preg_match('/[A-Z]{3}/', $value, $matches)) {
            return $matches[0];
        }

        // If we can't extract, try first 3 characters (uppercase)
        $code = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $value), 0, 3));
        
        // Pad with X if needed to ensure 3 characters
        while (strlen($code) < 3) {
            $code .= 'X';
        }

        return substr($code, 0, 3);
    }

    /**
     * Sanitize flight data for logging (remove sensitive info)
     */
    private function sanitizeFlightDataForLogging(array $flightData): array
    {
        $sanitized = $flightData;
        
        // Remove or mask any sensitive fields if needed
        // For now, we'll log the structure but could filter out prices, passenger names, etc.
        
        return $sanitized;
    }
}

