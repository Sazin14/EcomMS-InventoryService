<?php

return [

    'algo' => env('JWT_ALGO', 'RS256'),

    'public_key_path' => env(
        'JWT_PUBLIC_KEY_PATH',
        'storage/keys/jwt-public.pem'
    ),

];