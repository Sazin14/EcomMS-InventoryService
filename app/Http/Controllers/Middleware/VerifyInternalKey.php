<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Service-to-service calls (Order service, ProductService) send X-Internal-Key. */
class VerifyInternalKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('inventory.internal_key');
        $given = (string) $request->header('X-Internal-Key');

        if ($expected === '' || ! hash_equals($expected, $given)) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return $next($request);
    }
}
