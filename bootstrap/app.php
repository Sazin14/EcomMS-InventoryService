<?php

use App\Http\Middleware\CheckAuthorization;
use App\Http\Middleware\CheckPermission;
use App\Http\Middleware\VerifyInternalKey;
use App\Http\Middleware\VerifyJwt;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Requests arrive through nginx + Kong. Trust them, so request()->ip() is the real client
        // (rate limits, logs) and https is detected. TRUSTED_PROXIES: comma-separated IPs / CIDRs.
        $middleware->trustProxies(at: array_values(array_filter(array_map('trim', explode(',', (string) env(
            'TRUSTED_PROXIES',
            '127.0.0.1,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16'
        ))))));

        $middleware->alias([
            'auth.jwt' => VerifyJwt::class,
            'authz' => CheckAuthorization::class,
            'permission' => CheckPermission::class,
            'internal' => VerifyInternalKey::class,

        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
