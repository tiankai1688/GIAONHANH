<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

return Application::configure(basePath: dirname(__DIR__))
    ->withBindings([
        // P1-d: controllers depend on the interface; tests can rebind a mock.
        \App\Services\PaymentGatewayInterface::class => \App\Services\PaymentGatewayService::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Pure token API: CORS is global, auth is per-route via sanctum abilities.
        $middleware->api(append: [
            HandleCors::class,
        ]);
        // Role-scoped routes use `ability:customer|merchant|rider|admin`.
        $middleware->alias([
            'ability' => \App\Http\Middleware\EnsureTokenHasAbility::class,
            'token.expiry' => \App\Http\Middleware\CheckTokenExpiry::class,
        ]);
        // Rate limiters: aggressive on auth endpoints (prevent brute-force / bot reg).
        $middleware->limiter([
            'auth' => \Illuminate\Routing\Middleware\ThrottleRequests::class.':10,1',   // 10 req/min per IP (coarse; real lockout is per-account in AuthController)
            'ipn'  => \Illuminate\Routing\Middleware\ThrottleRequests::class.':120,1', // 120 req/min for payment webhooks
            'api'  => \Illuminate\Routing\Middleware\ThrottleRequests::class.':30,1',  // 30 req/min per IP for public/anon writes (e.g. agent signup)
        ]);
    })
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['auth:sanctum']]
    )
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->shouldRenderJsonKeys(true);
    })->create();
