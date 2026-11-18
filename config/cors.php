<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    // Comma separated. Production: the real storefront and admin origins, e.g.
    // CORS_ALLOWED_ORIGINS=https://shop.example.com,https://admin.example.com
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', (string) env(
        'CORS_ALLOWED_ORIGINS',
        'http://localhost:3000,http://localhost:3001,http://127.0.0.1:3000,http://127.0.0.1:3001'
    ))))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Authorization', 'Content-Type', 'Accept', 'Idempotency-Key', 'X-Requested-With'],

    'exposed_headers' => ['X-Request-ID'],

    'max_age' => 600,

    // Bearer tokens, no cookies.
    'supports_credentials' => false,
];
