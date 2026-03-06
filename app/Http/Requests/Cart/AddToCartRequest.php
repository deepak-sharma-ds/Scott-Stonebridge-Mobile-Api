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
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.merchandise_id' => ['required', 'string'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:999'],
            'lines.*.attributes' => ['sometimes', 'array'],
            'lines.*.attributes.*.key' => ['required_with:lines.*.attributes', 'string', 'max:255'],
            'lines.*.attributes.*.value' => ['required_with:lines.*.attributes', 'string', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'cart_id.required' => 'The cart ID is required.',
            'lines.required' => 'At least one line item is required.',
            'lines.array' => 'Lines must be an array.',
            'lines.min' => 'At least one line item is required.',
            'lines.*.merchandise_id.required' => 'Each line item must have a merchandise ID.',
            'lines.*.quantity.required' => 'Each line item must have a quantity.',
            'lines.*.quantity.min' => 'Quantity must be at least 1.',
            'lines.*.quantity.max' => 'Quantity cannot exceed 999.',
            'lines.*.attributes.array' => 'Line item attributes must be a valid array.',
        ]);
    }
}
