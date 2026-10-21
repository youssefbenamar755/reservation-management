<?php

return [
    // Use a persistent PRIVATE disk on multi-instance/ephemeral hosting.
    'disk' => env('WPHUB_DOCUMENT_DISK', 'local'),

    // Opt in only on a fresh, isolated validation environment. Never enable on production.
    'validation' => [
        'enabled' => (bool) env('WPHUB_VALIDATION_MODE', false),
        'mail_transport' => env('WPHUB_VALIDATION_MAIL_TRANSPORT', 'capture'),
        'recipients' => array_values(array_filter(array_map('trim', explode(',', (string) env('WPHUB_VALIDATION_RECIPIENTS', ''))))),
        'store_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env('WPHUB_VALIDATION_STORE_ORIGINS', ''))))),
        'capture_path' => storage_path('app/private/validation-mail'),
    ],
];
