<?php

namespace App\Http\Requests\Cart;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdateBuyerIdentityRequest extends BaseApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'cart_id' => ['required', 'string'],
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'cart_id.required' => 'The cart_id field is required.',
            'email.required' => 'The buyer email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.max' => 'The email address cannot exceed 255 characters.',
        ]);
    }
}
