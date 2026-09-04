<?php

namespace App\Services;

use App\Models\Coupon;
use Illuminate\Validation\ValidationException;

/**
 * Resolves a merchant-issued coupon by code for a specific merchant + subtotal.
 * Centralises the validation rules so both the /coupons/verify preview endpoint
 * and the order-creation path agree on what "valid" means.
 */
class CouponService
{
    /**
     * @return array{ coupon: Coupon, discount: float, funded_by: string }
     * @throws ValidationException 422 with coupon_code message on any failure
     */
    public function resolve(string $code, int $merchantId, float $subtotal): array
    {
        $coupon = Coupon::where('code', $code)
            ->where('merchant_id', $merchantId)
            ->first();

        if (! $coupon) {
            throw ValidationException::withMessages([
                'coupon_code' => 'Mã không hợp lệ.',
            ]);
        }

        $error = $coupon->isValidFor($merchantId, $subtotal);
        if ($error) {
            throw ValidationException::withMessages(['coupon_code' => $error]);
        }

        return [
            'coupon'    => $coupon,
            'discount'  => $coupon->applyTo($subtotal),
            'funded_by' => $coupon->funded_by,
        ];
    }
}
