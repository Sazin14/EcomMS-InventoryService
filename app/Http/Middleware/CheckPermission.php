<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    public function handle(
        Request $request,
        Closure $next,
        string $permission
    ): Response {

        $authorization =
            $request->attributes->get(
                'authorization'
            );

        if (! $authorization) {
            return response()->json([
                'message' =>
                    'Authorization information unavailable.',
            ], 503);
        }

        $permissions =
            $authorization['permissions'] ?? [];

        if (
            ! in_array(
                $permission,
                $permissions,
                true
            )
        ) {
            return response()->json([
                'message' =>
                    'You do not have permission to perform this action.',
            ], 403);
        }

        return $next($request);
    }
}