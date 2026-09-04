<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a cross-store merged order payload:
 *   groups: [ { merchant_id, items:[{product_id,qty}] }, ... ]
 * Money (coupon) is resolved server-side, never trusted from the client.
 */
class CreateMergedOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'delivery_type' => ['sometimes', 'in:instant,appointment'],
            'expect_time'   => ['nullable', 'date'],
            'pay_method'    => ['sometimes', 'in:momo,zalopay,cod'],
            'address'       => ['required', 'string', 'max:255'],
            'lat'           => ['nullable', 'numeric'],
            'lng'           => ['nullable', 'numeric'],
            'contact_name'  => ['required', 'string', 'max:60'],
            'contact_phone' => ['required', 'string', 'regex:/^[0-9]{9,11}$/'],
            'note'          => ['nullable', 'string', 'max:255'],

            'groups'                  => ['required', 'array', 'min:1', 'max:20'],
            'groups.*.merchant_id'    => ['required', 'exists:merchants,id'],
            'groups.*.coupon_code'    => ['nullable', 'string', 'max:32'],
            'groups.*.items'          => ['required', 'array', 'min:1', 'max:50'],
            'groups.*.items.*.product_id' => ['required', 'exists:products,id'],
            'groups.*.items.*.qty'         => ['required', 'integer', 'min:1', 'max:9999'],
        ];
    }
}
