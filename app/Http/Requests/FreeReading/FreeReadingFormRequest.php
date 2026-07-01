<?php

namespace App\Http\Requests\FreeReading;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class FreeReadingFormRequest extends BaseApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:255'],
            'last_name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'phone_country_code' => ['sometimes', 'nullable', 'string', 'regex:/^\+?\d{1,5}$/'],
            'phone' => ['required', 'string', 'regex:/^\+?[1-9]\d{1,14}$/'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'first_name.required' => 'Your first name is required.',
            'first_name.max' => 'The first name cannot exceed 255 characters.',
            'last_name.required' => 'Your last name is required.',
            'last_name.max' => 'The last name cannot exceed 255 characters.',
            'email.required' => 'Your email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.max' => 'The email address cannot exceed 255 characters.',
            'phone_country_code.regex' => 'Please provide a valid country dialling code (e.g., +91).',
            'phone.required' => 'Your phone number is required.',
            'phone.regex' => 'Please provide a valid phone number in international format (e.g., +1234567890).',
        ]);
    }
}
