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

        $cached = Redis::connection('authz')->get($key);   // shared with AuthService, see config/database.php

        if ($cached === null) {
            // no cache = the staff member was deleted, or Redis lost it: sign in again (login / refresh rebuild it)
            return response()->json([
                'message' => 'Your session has expired. Please sign in again.',
            ], 401);
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