<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;

class AmadeusDummyTicketGeneratorService
{
    /**
     * Generate a full Amadeus-style dummy ticket command block
     *
     * @param array $flightData Normalized flight data structure
     * @param array $passengerData Passenger information (passengers array, email, phone)
     * @return array{sell_line: string, passenger_line: string, contact_line: string, ticket_time_limit: string, received_from: string, end_transaction: string, full_command_block: string}
     */
    public function generateCommandBlock(array $flightData, array $passengerData = []): array
    {
        try {
            // 1. SELL / ROUTE LINE
            $sellLine = $this->generateSellLine($flightData);

            // 2. PASSENGER LINE (single NM line with all passengers)
            $passengers = $passengerData['passengers'] ?? [];
            $passengerLine = $this->generatePassengerLine($passengers);

            // 3. CONTACT LINE
            $contactLine = $this->generateContactLine($passengerData);

            // 4. TICKET TIME LIMIT
            $ticketTimeLimit = $this->generateTicketTimeLimit($flightData);

            // 5. RECEIVED FROM
            $receivedFrom = 'RF ATK';

            // 6. END TRANSACTION
            $endTransaction = 'ER';

            // Build full command block
            $commandLines = array_filter([
                $sellLine,
                $passengerLine, // Single passenger line
                $contactLine,
                $ticketTimeLimit,
                $receivedFrom,
                $endTransaction,
            ], fn($line) => !empty($line));

            $fullCommandBlock = implode("\n", $commandLines);

            // Count valid passengers (those with both first and last name)
            $validPassengers = array_filter($passengers, function ($p) {
                return !empty($p['first_name']) && !empty($p['last_name']);
            });
            
            Log::info('Amadeus dummy ticket command block generated', [
                'sell_line' => $sellLine,
                'passenger_line' => $passengerLine,
                'passenger_count' => count($validPassengers),
                'total_passengers_passed' => count($passengers),
                'has_contact' => !empty($contactLine),
                'full_command_block' => $fullCommandBlock,
            ]);

            return [
                'sell_line' => $sellLine,
                'passenger_line' => $passengerLine,
                'contact_line' => $contactLine,
                'ticket_time_limit' => $ticketTimeLimit,
                'received_from' => $receivedFrom,
                'end_transaction' => $endTransaction,
                'full_command_block' => $fullCommandBlock,
            ];
        } catch (\Throwable $e) {
            Log::error('Failed to generate Amadeus dummy ticket command block', [
                'error' => $e->getMessage(),
                'flight_data' => $this->sanitizeForLogging($flightData),
            ]);

            throw new \RuntimeException('Failed to generate Amadeus dummy ticket command block: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate SELL / ROUTE LINE
     * Format: {AIRLINE}{DD}{MMM}{ORIGIN}{DEST}/XIST/ATK
     * Example: SN30NOVSINDLA/XIST/ATK
     */
    private function generateSellLine(array $flightData): string
    {
        // Validate required fields
        if (empty($flightData['origin']) || empty($flightData['destination'])) {
            throw new \InvalidArgumentException('Origin and destination are required to generate SELL line');
        }

        if (empty($flightData['departure_date'])) {
            throw new \InvalidArgumentException('Departure date is required to generate SELL line');
        }

        // Get airline code (2 letters)
        $airline = $this->extractAirlineCode($flightData);

        // Format date (DD + MMM uppercase)
        $datePart = $this->formatDatePart($flightData['departure_date']);

        // Get origin and destination IATA codes (3 letters each)
        $origin = $this->extractIataCode($flightData['origin']);
        $destination = $this->extractIataCode($flightData['destination']);

        // Assemble: AIRLINE + DD + MMM + ORIGIN + DEST + "/XIST/ATK"
        return strtoupper($airline . $datePart . $origin . $destination . '/XIST/ATK');
    }

    /**
     * Generate SINGLE PASSENGER LINE for all passengers
     * Format: NM{COUNT}{LASTNAME1}/{FIRSTNAME1} {TITLE1}+{LASTNAME2}/{FIRSTNAME2} {TITLE2}+...
     * 
     * Examples:
     * - 1 passenger: NM1MECHAI/DJAWED MR
     * - 2 passengers: NM2MECHAI/DJAWED MR+BENALI/ALI MR
     * - 3 passengers: NM3MECHAI/DJAWED MR+BENALI/ALI MR+HADDAD/SARA MS
     * 
     * Rules:
     * - COUNT = total number of passengers
     * - LASTNAME always before FIRSTNAME (LASTNAME/FIRSTNAME)
     * - Use "+" as separator between passengers
     * - NEVER use placeholder words like "PASSENGER"
     * - Always uppercase
     * - Default title = MR if missing
     * - Support MR / MS / MRS / CHD
     * - Return empty string if no valid passengers
     * 
     * @param array $passengers Array of passenger data with first_name, last_name, title
     * @return string Single NM line with all passengers combined
     */
    private function generatePassengerLine(array $passengers): string
    {
        // Log input for debugging
        Log::debug('Generating passenger line', [
            'total_passengers' => count($passengers),
            'passengers_data' => $passengers,
        ]);
        
        // Filter out passengers without both first and last name
        $validPassengers = [];
        foreach ($passengers as $index => $passenger) {
            $lastName = $passenger['last_name'] ?? null;
            $firstName = $passenger['first_name'] ?? null;
            
            // Log each passenger for debugging
            Log::debug("Passenger {$index} check", [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'is_valid' => !empty($lastName) && !empty($firstName),
            ]);
            
            // Only include passengers with both first and last name
            if (!empty($lastName) && !empty($firstName)) {
                $validPassengers[] = $passenger;
            }
        }
        
        // Return empty string if no valid passengers
        if (empty($validPassengers)) {
            Log::warning('No valid passengers found for passenger line generation', [
                'total_passengers' => count($passengers),
            ]);
            return '';
        }
        
        $passengerParts = [];
        
        foreach ($validPassengers as $passenger) {
            $lastName = trim($passenger['last_name'] ?? '');
            $firstName = trim($passenger['first_name'] ?? '');
            $title = strtoupper(trim($passenger['title'] ?? 'MR'));
            
            // Validate title (must be one of: MR, MS, MRS, CHD)
            $validTitles = ['MR', 'MS', 'MRS', 'CHD'];
            if (!in_array($title, $validTitles)) {
                $title = 'MR'; // Default to MR if invalid
            }
            
            // Remove spaces and special characters, keep only alphanumeric, then uppercase
            $lastNameClean = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($lastName));
            $firstNameClean = preg_replace('/[^A-Za-z0-9]/', '', strtoupper($firstName));
            
            // Format: {LASTNAME}/{FIRSTNAME} {TITLE}
            $passengerParts[] = "{$lastNameClean}/{$firstNameClean} {$title}";
        }
        
        // COUNT = number of valid passengers
        $count = count($validPassengers);
        
        // Combine all passengers with "+" separator
        // Format: NM{COUNT}{PASSENGER1}+{PASSENGER2}+{PASSENGER3}+...
        $combinedPassengers = implode('+', $passengerParts);
        
        $passengerLine = "NM{$count}{$combinedPassengers}";
        
        Log::info('Passenger line generated successfully', [
            'passenger_line' => $passengerLine,
            'passenger_count' => $count,
        ]);
        
        return $passengerLine;
    }

    /**
     * Generate CONTACT LINE
     * Format: AP {EMAIL} or AP {PHONE}
     * Example: AP QWERTY-QWERTY@EMAIL.COM
     */
    private function generateContactLine(array $passengerData): string
    {
        $email = $passengerData['email'] ?? null;
        $phone = $passengerData['phone'] ?? $passengerData['telephone'] ?? $passengerData['mobile'] ?? null;

        // Prefer email, fallback to phone
        $contact = $email ?: $phone;

        if (empty($contact)) {
            return '';
        }

        // Clean contact info (remove spaces, convert to uppercase for email format)
        $contactClean = preg_replace('/\s+/', '-', strtoupper(trim($contact)));

        return "AP {$contactClean}";
    }

    /**
     * Generate TICKET TIME LIMIT
     * Format: TKTL{DD}{MMM}
     * Example: TKTL30NOV
     */
    private function generateTicketTimeLimit(array $flightData): string
    {
        if (empty($flightData['departure_date'])) {
            return '';
        }

        $datePart = $this->formatDatePart($flightData['departure_date']);
        return "TKTL{$datePart}";
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
     * Sanitize data for logging
     */
    private function sanitizeForLogging(array $data): array
    {
        return $data;
    }
}

