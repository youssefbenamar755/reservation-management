<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Amadeus API Configuration
    |--------------------------------------------------------------------------
    |
    | Credentials for the Amadeus Travel API.
    | Always use config('amadeus.x') — never call env() directly in application code.
    |
    */

    'client_id'     => env('AMADEUS_CLIENT_ID'),
    'client_secret' => env('AMADEUS_CLIENT_SECRET'),
    'base_url'      => env('AMADEUS_BASE_URL', 'https://test.api.amadeus.com'),
];
