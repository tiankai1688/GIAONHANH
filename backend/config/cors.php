<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | GIAONHANH is a Capacitor app (origin: capacitor://localhost) plus web
    | previews. List every allowed origin in CORS_ALLOWED_ORIGINS (.env).
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => explode(',', env('CORS_ALLOWED_ORIGINS', '*')),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // SECURITY (2026-08-01): credentials DISABLED. The API authenticates with
    // Sanctum bearer tokens (Authorization header), never with cookies/sessions,
    // so there is no reason to allow credentialed cross-origin requests. With
    // `supports_credentials` true + a wildcard origin, any website could issue
    // credentialed calls on behalf of a logged-in user. Set to true ONLY if you
    // later adopt cookie-based Sanctum SPA auth AND restrict allowed_origins to
    // a fixed allowlist (never '*').
    'supports_credentials' => false,

];
