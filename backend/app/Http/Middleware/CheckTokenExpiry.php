<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Safety-net expiry check for the short-lived access token.
 *
 * Sanctum already rejects expired access tokens natively (it inspects
 * personal_access_tokens.expires_at before authenticating). This middleware
 * runs *after* auth:sanctum and re-checks the current token's expiry so the
 * client receives a structured 401 ('token_expired') that it can distinguish
 * from other auth failures and use to trigger a refresh-token rotation.
 */
class CheckTokenExpiry
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->user()?->currentAccessToken();

        if ($token && $token->expires_at && $token->expires_at->isPast()) {
            return response()->json([
                'error' => 'token_expired',
                'message' => 'Phiên đăng nhập đã hết hạn, vui lòng làm mới.',
            ], 401);
        }

        return $next($request);
    }
}
