<?php

namespace App\Http\Middleware;

use Closure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class VerifyJwt
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {

        $header = $request->header('Authorization');

        if (! $header) {
            return response()->json([
                'message' => 'Authorization token is required.',
            ], 401);
        }

        if (
            ! str_starts_with(
                $header,
                'Bearer '
            )
        ) {
            return response()->json([
                'message' => 'Invalid authorization header.',
            ], 401);
        }

        $token = trim(
            substr($header, 7)
        );

        try {

            $publicKey = file_get_contents(
                base_path(config('jwt.public_key_path'))
            );

            if ($publicKey === false) {
                throw new \RuntimeException(
                    'JWT public key could not be loaded.'
                );
            }

            $payload = JWT::decode(
                $token,
                new Key(
                    $publicKey,
                    config('jwt.algo', 'RS256')
                )
            );

        } catch (Throwable $e) {

            return response()->json([
                'message' => 'Invalid or expired token.',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | Required Claims
        |--------------------------------------------------------------------------
        */

        if (
            ! isset($payload->sub)
            || ! isset($payload->type)
        ) {
            return response()->json([
                'message' => 'Invalid JWT claims.',
            ], 401);
        }

        /*
        |--------------------------------------------------------------------------
        | Store Authenticated Identity
        |--------------------------------------------------------------------------
        */

        $request->attributes->set(
            'jwt',
            $payload
        );

        $request->attributes->set(
            'user_id',
            (int) $payload->sub
        );

        $request->attributes->set(
            'user_type',
            $payload->type
        );

        return $next($request);
    }
}