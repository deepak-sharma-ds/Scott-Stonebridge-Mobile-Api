<?php

namespace App\Http\Requests\Cart;

use App\Http\Requests\BaseApiRequest;

class AddToCartRequest extends BaseApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cart_id' => ['required', 'string'],
            'variant_id' => ['required', 'string'],
            'quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'attributes' => ['sometimes', 'array'],
            'attributes.*.key' => ['required_with:attributes', 'string', 'max:255'],
            'attributes.*.value' => ['required_with:attributes', 'string', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'cart_id.required' => 'The cart ID is required.',
            'variant_id.required' => 'The variant ID is required.',
            'quantity.required' => 'The quantity is required.',
            'quantity.min' => 'Quantity must be at least 1.',
            'quantity.max' => 'Quantity cannot exceed 999.',
            'attributes.array' => 'The attributes must be a valid array.',
            'attributes.*.key.required_with' => 'Each attribute must have a key.',
            'attributes.*.value.required_with' => 'Each attribute must have a value.',
        ]);
    }
}
