<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Coupon;
use App\Services\CouponService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CouponController extends Controller
{
    public function __construct(private CouponService $couponService)
    {
    }

    /**
     * Customer-facing coupon preview. Given a code + merchant + cart subtotal,
     * returns the discount (or a 422 with a Vietnamese error). Does NOT consume
     * the coupon — redemption happens at order creation.
     *
     * Route: POST /api/coupons/verify  (ability:customer)
     */
    public function verify(Request $request)
    {
        $data = $request->validate([
            'code'         => ['required', 'string', 'max:32'],
            'merchant_id'  => ['required', 'exists:merchants,id'],
            'subtotal'     => ['required', 'numeric', 'min:0'],
        ]);

        try {
            $r = $this->couponService->resolve(
                $data['code'],
                (int) $data['merchant_id'],
                (float) $data['subtotal']
            );
        } catch (ValidationException $e) {
            return response()->json([
                'valid'   => false,
                'message' => $e->errors()['coupon_code'][0] ?? 'Mã không hợp lệ.',
            ], 422);
        }

        return response()->json([
            'valid'      => true,
            'code'       => $r['coupon']->code,
            'type'       => $r['coupon']->type,
            'value'      => (float) $r['coupon']->value,
            'discount'   => $r['discount'],
            'funded_by'  => $r['funded_by'],
            'merchant_name' => $r['coupon']->merchant?->name,
        ]);
    }

    /**
     * List the logged-in merchant's coupons.
     */
    public function index(Request $request)
    {
        $merchant = $request->user()->merchant;
        if (! $merchant) {
            return response()->json(['message' => 'Chưa có hồ sơ merchant.'], 404);
        }
        return response()->json(
            $merchant->coupons()->latest()->get()
        );
    }

    /**
     * Create a coupon. merchant_id is forced from the authenticated merchant
     * (never trusted from the request) to prevent cross-merchant writes.
     */
    public function store(Request $request)
    {
        $merchant = $request->user()->merchant;
        if (! $merchant) {
            return response()->json(['message' => 'Chưa có hồ sơ merchant.'], 404);
        }
        $data = $request->validate([
            'name'         => ['required', 'string', 'max:120'],
            'name_zh'      => ['nullable', 'string', 'max:120'],
            'type'         => ['required', Rule::in(['cash', 'percent'])],
            'value'        => ['required', 'numeric', 'min:0', Rule::when($request->type === 'percent', 'max:100')],
            'min_order'    => ['nullable', 'numeric', 'min:0'],
            'usage_limit'  => ['nullable', 'integer', 'min:1'],
            'start_at'     => ['nullable', 'date'],
            'end_at'       => ['nullable', 'date', 'after_or_equal:start_at'],
        ]);

        $data['merchant_id'] = $merchant->id;
        $data['code']        = Coupon::generateCode();
        $data['status']      = 'active';

        $coupon = Coupon::create($data);

        return response()->json($coupon, 201);
    }

    /**
     * Update mutable fields (name / value / status / limit). Ownership checked.
     */
    public function update(Request $request, Coupon $coupon)
    {
        $merchant = $request->user()->merchant;
        if (! $merchant || $coupon->merchant_id !== $merchant->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $data = $request->validate([
            'name'        => ['sometimes', 'string', 'max:120'],
            'name_zh'     => ['sometimes', 'string', 'max:120'],
            'value'       => ['sometimes', 'numeric', 'min:0', Rule::when($request->type === 'percent', 'max:100')],
            'min_order'   => ['sometimes', 'numeric', 'min:0'],
            'status'      => ['sometimes', Rule::in(['active', 'paused'])],
            'usage_limit' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'start_at'    => ['sometimes', 'nullable', 'date'],
            'end_at'      => ['sometimes', 'nullable', 'date', 'after_or_equal:start_at'],
        ]);
        $coupon->update($data);

        return response()->json($coupon);
    }

    /**
     * Delete a coupon. Ownership checked.
     */
    public function destroy(Request $request, Coupon $coupon)
    {
        $merchant = $request->user()->merchant;
        if (! $merchant || $coupon->merchant_id !== $merchant->id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }
        $coupon->delete();

        return response()->json(['ok' => true]);
    }
}
