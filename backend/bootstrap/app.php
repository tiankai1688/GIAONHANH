<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;

$app = Application::configure(basePath: dirname(__DIR__))
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
    })
    ->withExceptions(function (Exceptions $exceptions) {
    })->create();

// Named rate limiters, referenced in routes/api.php as `throttle:auth|ipn|api`.
// Registered on the `booted` callback so the RateLimiter facade root is set in
// BOTH the CLI (`php artisan …`) and `php artisan serve` (per-request) boot
// paths. Defining them inside withMiddleware — or immediately after create() —
// fires before the `rate.limiter` binding exists and throws
// "A facade root has not been set".
$app->booted(function () {
    \Illuminate\Support\Facades\RateLimiter::for('auth', function (\Illuminate\Http\Request $request) {
        return \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by($request->ip());
    });
    \Illuminate\Support\Facades\RateLimiter::for('ipn', function (\Illuminate\Http\Request $request) {
        return \Illuminate\Cache\RateLimiting\Limit::perMinute(120)->by($request->ip());
    });
    \Illuminate\Support\Facades\RateLimiter::for('api', function (\Illuminate\Http\Request $request) {
        return \Illuminate\Cache\RateLimiting\Limit::perMinute(30)->by($request->ip());
    });
});

return $app;
