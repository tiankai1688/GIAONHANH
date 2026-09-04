<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

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
    $response->assertJsonMissingKey('refresh_token');

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
    $response = $this->withCookie('gn_refresh_token', $value)
        ->postJson('/api/v1/auth/refresh');

    $response->assertOk();
    $response->assertJsonStructure(['token', 'expires_at', 'user']);
    $response->assertJsonMissingKey('refresh_token');

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
