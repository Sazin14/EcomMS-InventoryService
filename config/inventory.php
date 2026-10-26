<?php

return [
    // Shared secret the Order service (and ProductService) send in the X-Internal-Key header.
    // Replace with service-to-service JWTs later.
    'internal_key' => env('INTERNAL_SERVICE_KEY'),

    'reservation_ttl_minutes' => 15,
];
