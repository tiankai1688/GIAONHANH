<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'merchant_id'   => ['required', 'exists:merchants,id'],
            'delivery_type' => ['sometimes', 'in:instant,appointment'],
            'expect_time'   => ['nullable', 'date'],
            'pay_method'    => ['sometimes', 'in:momo,zalopay,cod'],
            'address'       => ['required', 'string', 'max:255'],
            'lat'           => ['nullable', 'numeric'],
            'lng'           => ['nullable', 'numeric'],
            'contact_name'  => ['required', 'string', 'max:60'],
            'contact_phone' => ['required', 'string', 'regex:/^[0-9]{9,11}$/'],
            'note'          => ['nullable', 'string', 'max:255'],
            // V2 (anti-subsidy-fraud): `coupon_discount` is intentionally NOT
            // accepted here. The discount is computed server-side in
            // OrderController::resolveServerCoupon() and never trusted from the
            // client, so a forged large coupon cannot zero out an order.
            // `coupon_code` is accepted but re-validated server-side against the
            // merchant + subtotal (CouponService), so a bad code is rejected 422.
            'coupon_code'   => ['nullable', 'string', 'max:32'],
            'items'         => ['required', 'array', 'min:1', 'max:50'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.qty'   => ['required', 'integer', 'min:1', 'max:9999'],
        ];
    }
}
