<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\BaseApiRequest;

class UpdateProfileRequest extends BaseApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:255'],
            'last_name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[1-9]\d{1,14}$/'],
            'accepts_marketing' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'first_name.string' => 'The first name must be a valid string.',
            'first_name.max' => 'The first name cannot exceed 255 characters.',
            'last_name.string' => 'The last name must be a valid string.',
            'last_name.max' => 'The last name cannot exceed 255 characters.',
            'phone.regex' => 'Please provide a valid phone number in international format (e.g., +1234567890).',
            'accepts_marketing.boolean' => 'The accepts marketing field must be true or false.',
        ]);
    }
}
