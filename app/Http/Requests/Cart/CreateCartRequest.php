<?php

namespace App\Http\Requests\Cart;

use App\Http\Requests\BaseApiRequest;

class CreateCartRequest extends BaseApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'line_items' => ['sometimes', 'array', 'max:250'],
            'line_items.*.variant_id' => ['required_with:line_items', 'string'],
            'line_items.*.quantity' => ['required_with:line_items', 'integer', 'min:1', 'max:999'],
            'buyer_identity' => ['sometimes', 'array'],
            'buyer_identity.email' => ['sometimes', 'email', 'max:255'],
            'buyer_identity.phone' => ['sometimes', 'string', 'max:20'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'line_items.array' => 'The line items must be a valid array.',
            'line_items.max' => 'Cannot add more than 250 line items to a cart.',
            'line_items.*.variant_id.required_with' => 'Each line item must have a variant ID.',
            'line_items.*.quantity.required_with' => 'Each line item must have a quantity.',
            'line_items.*.quantity.min' => 'Quantity must be at least 1.',
            'line_items.*.quantity.max' => 'Quantity cannot exceed 999.',
            'buyer_identity.email.email' => 'The buyer email must be a valid email address.',
        ]);
    }
}
