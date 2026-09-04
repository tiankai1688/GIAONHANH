<?php

use App\Http\Controllers\Api\AgentController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\MerchantController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\RiderController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\SettlementController;
use App\Http\Controllers\Api\CouponController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| GIAONHANH API Routes
|--------------------------------------------------------------------------
| Abilities issued with sanctum tokens: customer | merchant | rider | admin
|
| All routes are mounted under the `/api/v1` prefix (versioned). A future
| breaking change can ship as `/api/v2` without breaking existing clients.
*/

Route::prefix('v1')->group(function () {

// ---- Public ----
Route::get('/health', fn () => response()->json(['ok' => true, 'ts' => now()->timestamp]));
Route::get('/categories', [CategoryController::class, 'tree']);
Route::get('/merchants', [MerchantController::class, 'index']);
Route::get('/merchants/{merchant}', [MerchantController::class, 'show']);
Route::get('/merchants/{merchant}/products', [MerchantController::class, 'products']);
Route::get('/flash-sales', [MerchantController::class, 'flashSales']);
// Public agent application — throttled to blunt anonymous spam / enumeration.
Route::post('/agents', [AgentController::class, 'store'])->middleware('throttle:api');

Route::post('/auth/register', [AuthController::class, 'register'])->middleware('throttle:auth');
Route::post('/auth/register/verify', [AuthController::class, 'verifyRegistration'])->middleware('throttle:auth');
Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:auth');
// PUBLIC refresh endpoint (no auth:sanctum) — the only way to mint a new access
// token after expiry. Uses refresh-token rotation; throttled against brute-force.
Route::post('/auth/refresh', [AuthController::class, 'refresh'])->middleware('throttle:auth');

// ---- Authenticated device registration (any role may register its token) ----
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/device-token', [DeviceTokenController::class, 'store']);
    Route::delete('/device-token', [DeviceTokenController::class, 'destroy']);
    // P0#4 — data-subject account deletion (any authenticated user).
    Route::delete('/account', [AuthController::class, 'destroyAccount']);
    // Logout: revoke the current access token + expire the HttpOnly refresh cookie.
    Route::post('/auth/logout', [AuthController::class, 'logout']);
});

// ---- Payment gateway webhooks (public, signature-verified) ----
Route::post('/payments/momo/ipn', [PaymentController::class, 'momoIpn'])->middleware('throttle:ipn');
Route::post('/payments/zalopay/callback', [PaymentController::class, 'zaloPayCallback'])->middleware('throttle:ipn');
Route::post('/payments/aggregator/{name}/callback', [PaymentController::class, 'aggregatorCallback'])->middleware('throttle:ipn');

// ---- Customer ----
Route::middleware(['auth:sanctum', 'ability:customer', 'token.expiry'])->group(function () {
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/orders', [OrderController::class, 'store']);
    Route::post('/orders/merged', [OrderController::class, 'storeMerged']); // P0: cross-store merged order
    Route::get('/orders', [OrderController::class, 'mine']);
    Route::get('/orders/{order}', [OrderController::class, 'show']);
    Route::post('/orders/{order}/pay', [PaymentController::class, 'pay']);
    Route::post('/orders/{order}/cancel', [OrderController::class, 'cancel']);
    Route::get('/orders/{order}/payment-status', [PaymentController::class, 'status']);
    Route::post('/coupons/verify', [CouponController::class, 'verify']); // preview discount
    // Onboarding requires an authenticated customer (auth:sanctum + token.expiry
    // are enforced by this group): a PUBLIC onboard previously let an anonymous
    // POST create orphan merchants with user_id = null.
    Route::post('/merchant/onboard', [MerchantController::class, 'onboard']);
});

// ---- Merchant ----
Route::middleware(['auth:sanctum', 'ability:merchant', 'token.expiry'])->group(function () {
    Route::get('/merchant/me', [MerchantController::class, 'profile']);
    Route::put('/merchant/me', [MerchantController::class, 'updateProfile']);
    Route::get('/merchant/orders', [MerchantController::class, 'orders']);
    Route::get('/merchant/products', [MerchantController::class, 'myProducts']);
    Route::post('/merchant/products', [MerchantController::class, 'storeProduct']);
    Route::get('/merchant/settlements', [SettlementController::class, 'merchantIndex']);
    Route::post('/merchant/settlements/confirm', [SettlementController::class, 'confirmMerchant']);
    Route::put('/merchant/products/{product}', [MerchantController::class, 'updateProduct']);
    Route::post('/merchant/orders/{order}/accept', [MerchantController::class, 'acceptOrder']);
    Route::post('/merchant/orders/{order}/ready', [MerchantController::class, 'readyOrder']);
    Route::get('/merchant/coupons', [CouponController::class, 'index']);
    Route::post('/merchant/coupons', [CouponController::class, 'store']);
    Route::put('/merchant/coupons/{coupon}', [CouponController::class, 'update']);
    Route::delete('/merchant/coupons/{coupon}', [CouponController::class, 'destroy']);
});

// ---- Rider ----
Route::middleware(['auth:sanctum', 'ability:rider', 'token.expiry'])->group(function () {
    Route::get('/rider/orders', [RiderController::class, 'nearby']);
    Route::get('/rider/current', [RiderController::class, 'current']);
    Route::get('/rider/me', [RiderController::class, 'profile']);
    Route::put('/rider/me', [RiderController::class, 'updateProfile']);
    Route::post('/rider/location', [RiderController::class, 'updateLocation']);
    Route::post('/rider/orders/{order}/accept', [RiderController::class, 'accept']);
    Route::post('/rider/orders/{order}/deliver', [RiderController::class, 'deliver']);
});

// ---- Admin ----
// SECURITY (V1): the `ability:admin` middleware enforces that the token carries
// the server-assigned 'admin' ability. Because AuthController::register now
// forces role='customer' (never from the request), no client can mint an admin
// token — the admin role is granted only by an authenticated admin via the
// review flow. We reuse Sanctum's ability mechanism; no custom auth is added.
Route::middleware(['auth:sanctum', 'ability:admin', 'token.expiry'])->group(function () {
    Route::post('/admin/merchants/{merchant}/approve', [AdminController::class, 'approveMerchant']);
    Route::post('/admin/merchants/{merchant}/reject', [AdminController::class, 'rejectMerchant']);
    Route::get('/admin/agents', [AgentController::class, 'index']);
    Route::post('/admin/agents/{agent}/approve', [AgentController::class, 'approve']);
    Route::post('/admin/agents/{agent}/reject', [AgentController::class, 'reject']);
    Route::get('/admin/agents/{agent}', [AgentController::class, 'show']);
    Route::put('/admin/agents/{agent}', [AgentController::class, 'update']);
    Route::delete('/admin/agents/{agent}', [AgentController::class, 'destroy']);
    Route::get('/admin/settlement', [AdminController::class, 'settlement']);
    Route::get('/admin/dashboard', [AdminController::class, 'dashboard']);
    Route::get('/admin/merchants', [AdminController::class, 'merchants']);
    Route::get('/admin/settlements/merchants', [SettlementController::class, 'adminIndex']);
    Route::get('/admin/settlements/payouts', [SettlementController::class, 'adminPayouts']);
    Route::post('/admin/settlements/{merchant}/pay', [SettlementController::class, 'adminPayout']);
    Route::get('/admin/payouts', [AdminController::class, 'payouts']);
    Route::get('/admin/orders', [AdminController::class, 'orders']);
    Route::post('/admin/merchants/{merchant}/kyc/approve', [AdminController::class, 'approveKyc']);
    Route::post('/admin/merchants/{merchant}/kyc/reject', [AdminController::class, 'rejectKyc']);
});

});
