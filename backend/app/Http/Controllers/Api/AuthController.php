<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class AuthController extends Controller
{
    /**
     * Issue a short-lived access token + a long-lived refresh token.
     *
     * Enforces a single-active session: any prior access tokens and any prior
     * non-rotated refresh tokens for the user are revoked. When $rotateFrom is
     * supplied (refresh flow) that token is revoked and linked to its
     * replacement via replaced_by (refresh-token rotation chain).
     */
    protected function issueTokens(User $user, string $role, ?RefreshToken $rotateFrom = null): array
    {
        // Revoke all existing access tokens (single-active access).
        $user->tokens()->delete();

        // Revoke any other live refresh tokens (single-active session), except
        // the one being rotated (handled below).
        $stale = $user->refreshTokens()->where('revoked', false);
        if ($rotateFrom) {
            $stale->where('id', '!=', $rotateFrom->id);
        }
        $stale->update(['revoked' => true]);

        // Short-lived access token — Sanctum enforces expires_at natively.
        $access = $user->createToken('gn-' . $role, [$role]);
        $access->accessToken->expires_at = now()->addHours(2);
        $access->accessToken->save();

        // Long-lived refresh token — stored hashed (never the plaintext).
        $plain = Str::random(64);
        $refresh = RefreshToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'ability' => $role,
            'expires_at' => now()->addDays(30),
            'revoked' => false,
        ]);

        // Link the rotated token to its replacement (rotation chain).
        if ($rotateFrom) {
            $rotateFrom->revoked = true;
            $rotateFrom->replaced_by = $refresh->id;
            $rotateFrom->save();
        }

        return [
            'token' => $access->plainTextToken,
            'refresh_token' => $plain,
            'expires_at' => $access->accessToken->expires_at->toIso8601String(),
            'user' => $user->only('id', 'name', 'phone', 'role'),
        ];
    }

    /**
     * Build the auth JSON response. The short-lived access token is returned in
     * the JSON body (the SPA needs it). The long-lived refresh token is issued
     * ONLY as an HttpOnly cookie so JavaScript — and therefore any XSS payload —
     * cannot read it (senior-review-2 fix for 3.4). Rotation still limits blast
     * radius if the access token is stolen. The refresh token is NEVER echoed
     * back in the JSON body.
     */
    protected function respondWithTokens(User $user, string $role, ?RefreshToken $rotateFrom = null, int $status = 200): JsonResponse
    {
        $tokens = $this->issueTokens($user, $role, $rotateFrom);

        // Strip the refresh token from the body — it lives in the HttpOnly cookie.
        $body = collect($tokens)->except('refresh_token')->all();

        $secure = config('app.env') === 'production';
        $cookie = cookie(
            'gn_refresh_token',
            $tokens['refresh_token'],
            43200,                                   // 30 days (matches RefreshToken.expires_at)
            '/',
            null,                                    // host-only cookie
            $secure,                                 // Secure in production (HTTPS only)
            true,                                    // HttpOnly — not readable by JS (XSS-safe)
            false,
            $secure ? 'none' : 'lax'                 // 'none' enables cross-origin (CDN SPA); 'lax' for local http
        );

        return response()->json($body, $status)->withCookie($cookie);
    }

    /**
     * Expire the HttpOnly refresh cookie. JS cannot clear an HttpOnly cookie, so
     * the server must invalidate it on logout / account deletion.
     */
    protected function expiredRefreshCookie(): \Symfony\Component\HttpFoundation\Cookie
    {
        $secure = config('app.env') === 'production';
        return cookie('gn_refresh_token', '', -1, '/', null, $secure, true, false, false, $secure ? 'none' : 'lax');
    }

    /**
     * Customer registration — STEP 1 of 2 (request OTP).
     *
     * Per red-team-review boss #2 / hacker #2 this MUST NOT issue a token or
     * create the account immediately. Instead we generate a 6-digit OTP, store
     * the (hashed) pending registration in cache for 10 minutes, and rate-limit
     * OTP issuance to one per phone per 60s. The caller must then hit
     * POST /auth/register/verify with the OTP to actually create the account.
     *
     * In production the OTP would be delivered over SMS by an OTP provider;
     * this code only GENERATES + VERIFIES it. The plaintext OTP is returned in
     * the response ONLY when app.debug is on (local/dev) so the flow can be
     * exercised without a real SMS gateway — in production it is never sent
     * back, so an automated farm cannot self-serve the code.
     */
    public function register(Request $request)
    {
        // SECURITY (V1): `role` is NEVER accepted from the client.
        $data = $request->validate([
            'name'  => ['required', 'string', 'max:60'],
            'phone' => ['required', 'string', 'regex:/^[0-9]{9,11}$/', Rule::unique('users', 'phone')],
            'password' => ['required', 'string', 'min:6'],
        ]);
        unset($data['role']);

        // One OTP per phone per 60s — blunts OTP-flooding / enumeration.
        $cooldown = 'reg:cooldown:' . $data['phone'];
        if (Cache::get($cooldown)) {
            return response()->json([
                'message' => 'Vui lòng đợi 60 giây trước khi yêu cầu mã mới.',
            ], 429);
        }

        $otp = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        Cache::put('reg:pending:' . $data['phone'], [
            'name'     => $data['name'],
            'password' => Hash::make($data['password']),
            'otp_hash' => hash('sha256', $otp),
            'expires'  => now()->addMinutes(10)->timestamp,
        ], now()->addMinutes(10));
        Cache::put($cooldown, true, now()->addSeconds(60));

        $resp = [
            'message' => 'Mã xác thực đã được gửi đến số điện thoại của bạn.',
            'phone'   => $data['phone'],
        ];
        if (config('app.debug')) {
            $resp['otp'] = $otp; // dev/test only — never returned in production
        }
        return response()->json($resp, 200);
    }

    /**
     * Customer registration — STEP 2 of 2 (verify OTP, create account).
     *
     * Verifies the OTP against the cached pending registration, then creates the
     * 'customer' account and issues tokens. Wrong/expired OTP → 422 (no account
     * is created). This is the gate that stops scripted fake-account farming of
     * platform-funded new-user coupons (red-team boss #2).
     */
    public function verifyRegistration(Request $request)
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'regex:/^[0-9]{9,11}$/'],
            'otp'   => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ]);

        // Re-check uniqueness in case of a race between step 1 and step 2.
        if (User::where('phone', $data['phone'])->exists()) {
            return response()->json(['message' => 'Số điện thoại đã được đăng ký.'], 409);
        }

        $pending = Cache::get('reg:pending:' . $data['phone']);
        if (! $pending || ($pending['expires'] ?? 0) < now()->timestamp) {
            return response()->json(['message' => 'Mã xác thực đã hết hạn. Vui lòng đăng ký lại.'], 422);
        }
        if (! hash_equals((string) ($pending['otp_hash'] ?? ''), hash('sha256', $data['otp']))) {
            return response()->json(['message' => 'Mã xác thực không đúng.'], 422);
        }

        $user = User::create([
            'name'    => $pending['name'],
            'phone'   => $data['phone'],
            'role'    => 'customer', // server-assigned; never taken from request
            'password' => $pending['password'],
        ]);
        Cache::forget('reg:pending:' . $data['phone']);
        Cache::forget('reg:cooldown:' . $data['phone']);

        return $this->respondWithTokens($user, $user->role, null, 201);
    }

    /**
     * Login by phone (mock OTP verification).
     */
    public function login(Request $request)
    {
        // SECURITY (P0-2): password is mandatory and ALWAYS verified. An account
        // with no password (e.g. registered without one) can NEVER authenticate
        // — this blocks account takeover via empty-password login.
        $data = $request->validate([
            'phone'    => ['required', 'string', 'regex:/^[0-9]{9,11}$/'],
            'password' => ['required', 'string'],
        ]);

        $phone = $data['phone'];

        // P0#7 — account lockout: block the account for 15 min after 5
        // consecutive failed attempts (protects against password brute-force and
        // satisfies the "processing security" obligation under PDPD Art.24 / GDPR
        // Art.32). The route-level `throttle:auth` (10/min/IP) is a secondary,
        // coarser control.
        $lockKey = 'login_lock:' . $phone;
        if (Cache::get($lockKey)) {
            return response()->json([
                'message' => 'Quá nhiều lần đăng nhập sai. Vui lòng thử lại sau 15 phút.',
            ], 429);
        }

        $user = User::where('phone', $phone)->first();
        if (! $user) {
            $this->registerFailedLogin($phone);
            return response()->json(['message' => 'Số điện thoại chưa đăng ký.'], 404);
        }
        if (! Hash::check($data['password'], (string) $user->password)) {
            $this->registerFailedLogin($phone);
            return response()->json(['message' => 'Mật khẩu không đúng.'], 401);
        }

        // Success — clear the failed-attempt counter.
        Cache::forget('login_fail:' . $phone);

        return $this->respondWithTokens($user, $user->role);
    }

    /**
     * Record a failed login attempt for $phone and lock the account after 5
     * consecutive failures. The lock is keyed by phone (not just IP) so a
     * distributed brute-force from many IPs still trips the per-account lock.
     */
    protected function registerFailedLogin(string $phone): void
    {
        $key = 'login_fail:' . $phone;
        $attempts = Cache::increment($key, 1, 1);
        if ($attempts >= 5) {
            Cache::put('login_lock:' . $phone, true, now()->addMinutes(15));
            Cache::forget($key);
        }
    }

    /**
     * Refresh an access token using a valid refresh token. Implements refresh
     * token rotation: the presented refresh token is revoked and replaced by a
     * new access+refresh pair. Returns 401 on an invalid / expired / revoked
     * refresh token. This route is PUBLIC (no auth:sanctum) — it is the only
     * way to obtain a new access token once the old one expires.
     */
    public function refresh(Request $request)
    {
        // SECURITY (senior-review-2 fix 3.4): the refresh token is issued as an
        // HttpOnly cookie (see respondWithTokens), so read it from the cookie
        // first. The request body is accepted only as a fallback for non-cookie
        // clients (native). The token is NEVER echoed back in the JSON body, so
        // an XSS payload cannot read or replay it from JS.
        $plain = $request->cookie('gn_refresh_token') ?? $request->input('refresh_token');

        if (! is_string($plain) || $plain === '') {
            return response()->json([
                'error' => 'invalid_refresh_token',
                'message' => 'Phiên làm mới không hợp lệ hoặc đã hết hạn.',
            ], 401);
        }

        $hash = hash('sha256', $plain);
        $refresh = RefreshToken::where('token_hash', $hash)->first();

        if (! $refresh || ! $refresh->isValid()) {
            return response()->json([
                'error' => 'invalid_refresh_token',
                'message' => 'Phiên làm mới không hợp lệ hoặc đã hết hạn.',
            ], 401);
        }

        $user = $refresh->user;
        if (! $user) {
            return response()->json([
                'error' => 'invalid_refresh_token',
                'message' => 'Tài khoản không tồn tại.',
            ], 401);
        }

        // Rotate: issue a new pair (new HttpOnly cookie), revoking the presented
        // refresh token. Only the new access token is returned in the body.
        return $this->respondWithTokens($user, $refresh->ability, $rotateFrom = $refresh);
    }

    /**
     * Log out the current device: revoke the access token and expire the
     * HttpOnly refresh cookie (JS cannot clear an HttpOnly cookie directly).
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        return response()->json(['message' => 'Đã đăng xuất. / 已退出登录。'])
            ->withCookie($this->expiredRefreshCookie());
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->only('id', 'name', 'phone', 'role'));
    }

    /**
     * P0#4 — Data-subject account deletion (right to erasure, PDPD Art.9 /
     * GDPR Art.17). Anonymizes all direct PII on the account, wipes PII on the
     * linked merchant/rider profiles, revokes every active session, and
     * soft-deletes the user. The row is RETAINED (not hard-deleted) so historical
     * orders and settlements remain auditable for the statutory retention
     * period (transactions >= 5 years for tax / AML). Only identifying fields
     * are wiped; the commercial record survives.
     */
    public function destroyAccount(Request $request)
    {
        $user = $request->user();

        // 1) Anonymize direct PII on the account.
        $user->update([
            'name'     => null,
            'phone'    => null,
            'email'    => null,
            'password' => Hash::make(Str::random(32)),
            'lat'      => null,
            'lng'      => null,
        ]);

        // 2) Anonymize PII on linked profiles; records retained for retention.
        if ($merchant = $user->merchant) {
            $merchant->update([
                'contact_name'     => null,
                'phone'            => null,
                'email'            => null,
                'address'          => null,
                'business_license' => null,
                'bank_account'     => null,
            ]);
        }
        if ($rider = $user->rider) {
            $rider->update([
                'name'    => null,
                'phone'   => null,
                'id_card' => null,
                'lat'     => null,
                'lng'     => null,
            ]);
        }

        // 2b) Anonymize PII on the user's historical orders (red-team S5 / PDPD
        // right to erasure). The commercial record (amounts, items, settlement)
        // is retained for the statutory audit period; only identifying delivery
        // data (name / phone / address / GPS / note) is wiped. Without this the
        // customer's home address + phone survive the account deletion forever.
        Order::where('user_id', $user->id)->update([
            'contact_name' => null,
            'contact_phone' => null,
            'address' => null,
            'lat' => null,
            'lng' => null,
            'note' => null,
        ]);

        // 3) Revoke every active session (access + refresh tokens).
        $user->tokens()->delete();
        $user->refreshTokens()->update(['revoked' => true]);

        // 4) Soft-delete the account (row kept for audit retention).
        $user->delete();

        // Expire the HttpOnly refresh cookie so a lingering browser tab cannot
        // silently refresh the now-deleted session.
        return response()->json([
            'message' => 'Tài khoản đã được xóa. / 账号已注销。',
        ], 200)->withCookie($this->expiredRefreshCookie());
    }
}
