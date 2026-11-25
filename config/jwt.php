<?php

return [
    'public_key_path' => env('JWT_PUBLIC_KEY_PATH', 'config/jwt-public.pem'),
    'algo' => env('JWT_ALGO', 'RS256'),
];
