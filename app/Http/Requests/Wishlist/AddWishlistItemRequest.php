<?php

namespace App\Http\Requests\Wishlist;

use App\Http\Requests\BaseApiRequest;

class AddWishlistItemRequest extends BaseApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'string'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'product_id.required' => 'The product ID is required.',
            'product_id.string' => 'The product ID must be a valid string.',
        ]);
    }
}
