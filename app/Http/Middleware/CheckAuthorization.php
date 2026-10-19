<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class CheckAuthorization
{
    public function handle(
        Request $request,
        Closure $next
    ): Response {

        $userId = $request->attributes->get(
            'user_id'
        );

        $userType = $request->attributes->get(
            'user_type'
        );

        /*
        |--------------------------------------------------------------------------
        | Only Staff Can Access Protected Management Routes
        |--------------------------------------------------------------------------
        */

        if ($userType !== 'staff') {
            return response()->json([
                'message' => 'Staff authentication required.',
            ], 403);
        }

        /*
        |--------------------------------------------------------------------------
        | Redis Authorization Cache
        |--------------------------------------------------------------------------
        */

        $key = "authz:staff:{$userId}";

        $cached = Redis::get($key);

        if ($cached === null) {

            return response()->json([
                'message' =>
                    'Authorization information unavailable.',
            ], 503);
        }

        $authorization = json_decode(
            $cached,
            true
        );

        if (! is_array($authorization)) {

            return response()->json([
                'message' =>
                    'Invalid authorization cache.',
            ], 503);
        }

        /*
        |--------------------------------------------------------------------------
        | Attach Authorization Data
        |--------------------------------------------------------------------------
        */

        $request->attributes->set(
            'authorization',
            $authorization
        );

        return $next($request);
    }
}