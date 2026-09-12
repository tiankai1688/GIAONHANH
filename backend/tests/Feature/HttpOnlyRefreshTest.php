<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

uses(\Illuminate\Foundation\Testing\RefreshDatabase::class);

beforeEach(function () {
    app()->bind(\App\Services\PaymentGatewayInterface::class, \App\Services\PaymentGatewayService::class);
});

function authUser(): User
{
    return User::create([
        'name'     => 'Cookie User',
        'phone'    => '090' . random_int(1000000, 9999999),
        'password' => Hash::make('secret123'),
        'role'     => 'customer',
    ]);
}

function refreshCookieValue($response): ?string
{
    foreach ($response->headers->getCookies() as $c) {
        if ($c->getName() === 'gn_refresh_token') {
            return $c->getValue();
        }
    }
    return null;
}

it('issues access token in body but refresh token only as an HttpOnly cookie', function () {
    $user = authUser();

    $response = $this->postJson('/api/v1/auth/login', [
        'phone'    => $user->phone,
        'password' => 'secret123',
    ]);

    $response->assertOk();
    $response->assertJsonStructure(['token', 'expires_at', 'user']);

    // SECURITY (senior-review-2 fix 3.4): the refresh token must NEVER appear
    // in the JSON body, otherwise an XSS payload could read and replay it.
    $response->assertJsonMissingPath('refresh_token');

    // A refresh cookie must be present and be HttpOnly (unreadable by JS).
    $cookie = null;
    foreach ($response->headers->getCookies() as $c) {
        if ($c->getName() === 'gn_refresh_token') {
            $cookie = $c;
        }
    }
    expect($cookie)->not->toBeNull('refresh cookie must be set');
    expect($cookie->isHttpOnly())->toBeTrue('refresh cookie must be HttpOnly');
});

it('rotates via the HttpOnly cookie and never re-echoes the refresh token', function () {
    $user = authUser();

    $login = $this->postJson('/api/v1/auth/login', [
        'phone'    => $user->phone,
        'password' => 'secret123',
    ]);
    $value = refreshCookieValue($login);
    expect($value)->not->toBeNull();

    // Refresh using ONLY the HttpOnly cookie (no token in the request body).
    // `call()` passes the cookie as a flat name=>value map straight into the
    // request cookies bag — the faithful equivalent of a browser replaying the
    // HttpOnly cookie. (withCookie() wraps the value in metadata the test
    // client fails to unwrap; withHeader('Cookie', …) is ignored by
    // Request::create() entirely, so neither reaches $request->cookie().)
    $response = $this->call(
        'POST',
        '/api/v1/auth/refresh',
        [],
        ['gn_refresh_token' => $value],
        [],
        ['HTTP_ACCEPT' => 'application/json']
    );

    if ($response->status() !== 200) {
        fwrite(STDERR, "DIAG_RC=" . $response->status() . "\n");
        fwrite(STDERR, "DIAG_BODY=" . $response->getContent() . "\n");
        fwrite(STDERR, "DIAG_VALUE_PRESENT=" . ($value !== null ? 'yes' : 'no') . "\n");
        fwrite(STDERR, "DIAG_VALUE_HEAD=" . substr((string) $value, 0, 12) . "\n");
        fwrite(STDERR, "DIAG_VALUE_HASH=" . substr(hash('sha256', (string) $value), 0, 12) . "\n");
        fwrite(STDERR, "DIAG_RECEIVED=" . ($this->app['request']->cookie('gn_refresh_token') ?? 'NULL') . "\n");
        fwrite(STDERR, "DIAG_RT_LATEST_HEAD=" . (($l = \App\Models\RefreshToken::latest('id')->first()) ? substr($l->token_hash, 0, 12) : 'NULL') . "\n");
    }
    $response->assertOk();
    $response->assertJsonStructure(['token', 'expires_at', 'user']);
    $response->assertJsonMissingPath('refresh_token');

    // Rotation re-issues a fresh HttpOnly cookie with a new value.
    $new = refreshCookieValue($response);
    expect($new)->not->toBeNull();
    expect($new)->not->toBe($value, 'refresh token must rotate');
});

it('rejects refresh when no cookie and no body are supplied', function () {
    $this->postJson('/api/v1/auth/refresh', [])->assertStatus(401);
});

it('expires the refresh cookie on logout', function () {
    $user = authUser();

    $login = $this->postJson('/api/v1/auth/login', [
        'phone'    => $user->phone,
        'password' => 'secret123',
    ]);
    $value = refreshCookieValue($login);
    expect($value)->not->toBeNull();

    $token = $login->json('token');

    $response = $this->withHeader('Authorization', 'Bearer ' . $token)
        ->postJson('/api/v1/auth/logout');

    $response->assertOk();

    // The refresh cookie must be expired (Max-Age 0 / negative expiry).
    $expired = null;
    foreach ($response->headers->getCookies() as $c) {
        if ($c->getName() === 'gn_refresh_token') {
            $expired = $c;
        }
    }
    expect($expired)->not->toBeNull();
    expect($expired->getExpiresTime())->toBeLessThanOrEqual(time(), 'logout must expire the refresh cookie');
});
