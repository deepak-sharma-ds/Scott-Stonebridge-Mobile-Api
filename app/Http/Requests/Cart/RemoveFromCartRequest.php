<?php

namespace App\Http\Requests\Cart;

use App\Http\Requests\BaseApiRequest;

class RemoveFromCartRequest extends BaseApiRequest
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
            'line_id' => ['required', 'string'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'cart_id.required' => 'The cart ID is required.',
            'line_id.required' => 'The line item ID is required.',
        ]);
    }
}
