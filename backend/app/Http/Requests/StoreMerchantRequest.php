<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'          => ['required', 'string', 'max:120'],
            'contact_name'  => ['required', 'string', 'max:60'],
            'phone'         => ['required', 'string', 'regex:/^[0-9]{9,11}$/'],
            'email'         => ['nullable', 'email', 'max:160'],
            'category_id'   => ['required', 'exists:categories,id'],
            'address'       => ['required', 'string', 'max:255'],
            'lat'           => ['nullable', 'numeric'],
            'lng'           => ['nullable', 'numeric'],
            'business_hours'=> ['nullable', 'string', 'max:40'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Số điện thoại không hợp lệ (9-11 chữ số).',
        ];
    }
}
