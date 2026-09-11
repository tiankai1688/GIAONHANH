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
        // Laravel 11 has no Middleware::limiter(); named limiters are registered via
        // RateLimiter::for() and referenced in routes as `throttle:auth|ipn|api`.
        \Illuminate\Support\Facades\RateLimiter::for('auth', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by($request->ip());
        });
        \Illuminate\Support\Facades\RateLimiter::for('ipn', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by($request->ip());
        });
        \Illuminate\Support\Facades\RateLimiter::for('api', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by($request->ip());
        });
    })
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['auth:sanctum']]
    )
    ->withExceptions(function (Exceptions $exceptions) {
    })->create();
