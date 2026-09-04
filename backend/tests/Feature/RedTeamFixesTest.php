<?php

use App\Models\Merchant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Rider;
use App\Models\User;
use App\Services\PaymentGatewayInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

/*
 * Regression tests for the red-team "immediate fix" (docs/red-team-review).
 * Each test FAILS if the corresponding weakness regresses. Sandbox has no PHP,
 * so these are aligned against source by inspection; CI (pest) is authoritative.
 *
 * Covers:
 *   - geofence: /rider/orders only returns orders within grab_radius_km
 *   - registration OTP gate: no token until OTP verified; wrong OTP rejected
 *   - PSP fee recorded: wallet > 0, COD == 0
 */

uses(\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

it('only shows picked orders within the grab radius (geofence)', function () {
    $user = User::create([
        'name' => 'Rider', 'phone' => '0901113301',
        'password' => Hash::make('secret123'), 'role' => 'rider',
    ]);
    $rider = Rider::create(['user_id' => $user->id, 'name' => 'R', 'phone' => '0901113301', 'lat' => 10.700, 'lng' => 106.700]);

    $near = Merchant::create(['name' => 'Near', 'address' => 'A', 'lat' => 10.710, 'lng' => 106.710, 'status' => 'approved', 'is_open' => true]);
    $far  = Merchant::create(['name' => 'Far',  'address' => 'B', 'lat' => 10.900, 'lng' => 106.900, 'status' => 'approved', 'is_open' => true]);

    $nearOrder = Order::create([
        'order_no' => 'GN' . uniqid(), 'user_id' => 1, 'merchant_id' => $near->id,
        'status' => 'picked', 'amount' => 50000.0, 'product_amount' => 50000.0,
    ]);
    Order::create([
        'order_no' => 'GN' . uniqid(), 'user_id' => 1, 'merchant_id' => $far->id,
        'status' => 'picked', 'amount' => 50000.0, 'product_amount' => 50000.0,
    ]);

    $res = $this->withToken($user->createToken('ci', ['rider'])->plainTextToken)
        ->getJson('/api/v1/rider/orders?lat=10.700&lng=106.700');

    $res->assertStatus(200);
    $data = $res->json('data');
    expect($data)->toHaveCount(1);
    expect($data[0]['order_no'])->toBe($nearOrder->order_no);
});

it('does not leak customer PII in the grab feed (masked phone, no name)', function () {
    $user = User::create([
        'name' => 'Rider', 'phone' => '0901113302',
        'password' => Hash::make('secret123'), 'role' => 'rider',
    ]);
    Rider::create(['user_id' => $user->id, 'name' => 'R', 'phone' => '0901113302', 'lat' => 10.700, 'lng' => 106.700]);
    $m = Merchant::create(['name' => 'M', 'address' => 'A', 'lat' => 10.710, 'lng' => 106.710, 'status' => 'approved', 'is_open' => true]);
    Order::create([
        'order_no' => 'GN' . uniqid(), 'user_id' => 1, 'merchant_id' => $m->id,
        'status' => 'picked', 'amount' => 50000.0, 'product_amount' => 50000.0,
        'contact_name' => 'Nguyen Van A', 'contact_phone' => '0901234567',
    ]);

    $res = $this->withToken($user->createToken('ci', ['rider'])->plainTextToken)
        ->getJson('/api/v1/rider/orders?lat=10.700&lng=106.700');

    $res->assertStatus(200);
    $row = $res->json('data.0');
    expect($row['contact']['name'])->toBeNull();
    expect($row['contact']['phone'])->toBe('*******567'); // last 3 digits only
});

it('does not issue a token on register and requires OTP verification', function () {
    config(['app.debug' => true]); // ensure the OTP is returned for the test
    $phone = '0901113303';
    $resp = $this->postJson('/api/v1/auth/register', [
        'name' => 'New', 'phone' => $phone, 'password' => 'secret123',
    ]);
    $resp->assertStatus(200);
    expect(User::where('phone', $phone)->exists())->toBeFalse(); // no account yet

    $otp = $resp->json('otp'); // returned only when app.debug (test env)
    expect($otp)->not->toBeNull();

    // wrong OTP → rejected, still no account
    $this->postJson('/api/v1/auth/register/verify', ['phone' => $phone, 'otp' => '000000'])
        ->assertStatus(422);
    expect(User::where('phone', $phone)->exists())->toBeFalse();

    // correct OTP → account created + token issued
    $ok = $this->postJson('/api/v1/auth/register/verify', ['phone' => $phone, 'otp' => $otp]);
    $ok->assertStatus(201)->assertJsonStructure(['token', 'refresh_token']);
    expect(User::where('phone', $phone)->exists())->toBeTrue();
});

it('records the PSP fee on a wallet payment (unit economics visible)', function () {
    $user = User::create([
        'name' => 'C', 'phone' => '0901113304',
        'password' => Hash::make('secret123'), 'role' => 'customer',
    ]);
    $order = Order::create([
        'order_no' => 'GN' . uniqid(), 'user_id' => $user->id, 'merchant_id' => 1,
        'status' => 'pending_payment', 'amount' => 100000.0, 'product_amount' => 100000.0,
    ]);

    $gateway = Mockery::mock(PaymentGatewayInterface::class);
    $gateway->shouldReceive('createPayment')->andReturn([
        'status' => 'pending', 'pay_url' => 'http://x', 'trans_id' => 't1', 'raw' => [],
    ]);
    $this->app->instance(PaymentGatewayInterface::class, $gateway);

    $res = $this->withToken($user->createToken('ci', ['customer'])->plainTextToken)
        ->postJson('/api/v1/orders/' . $order->order_no . '/pay', ['method' => 'momo']);
    $res->assertStatus(200);

    $fresh = $order->fresh();
    expect((float) $fresh->psp_fee)->toBeGreaterThan(0.0);      // 100000 * 0.025 = 2500
    expect($fresh->psp_fee_bearer)->toBe('platform');
    expect((float) Payment::where('order_id', $fresh->id)->first()->psp_fee)->toBeGreaterThan(0.0);
});

it('charges no PSP fee on COD', function () {
    $user = User::create([
        'name' => 'C', 'phone' => '0901113305',
        'password' => Hash::make('secret123'), 'role' => 'customer',
    ]);
    $order = Order::create([
        'order_no' => 'GN' . uniqid(), 'user_id' => $user->id, 'merchant_id' => 1,
        'status' => 'pending_payment', 'amount' => 100000.0, 'product_amount' => 100000.0,
    ]);

    $res = $this->withToken($user->createToken('ci', ['customer'])->plainTextToken)
        ->postJson('/api/v1/orders/' . $order->order_no . '/pay', ['method' => 'cod']);
    $res->assertStatus(200);

    $fresh = $order->fresh();
    expect((float) $fresh->psp_fee)->toBe(0.0);
});
