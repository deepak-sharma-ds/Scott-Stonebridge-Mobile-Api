<?php

namespace App\Http\Requests\Profile;

use App\Http\Requests\BaseApiRequest;

class AddAddressRequest extends BaseApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'address1' => ['required', 'string', 'max:255'],
            'address2' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'province' => ['sometimes', 'nullable', 'string', 'max:255'],
            'country' => ['required', 'string', 'max:255'],
            'zip' => ['required', 'string', 'max:20'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[1-9]\d{1,14}$/'],
            'first_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'last_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'address1.required' => 'The address line 1 is required.',
            'address1.max' => 'The address line 1 cannot exceed 255 characters.',
            'address2.max' => 'The address line 2 cannot exceed 255 characters.',
            'city.required' => 'The city is required.',
            'city.max' => 'The city cannot exceed 255 characters.',
            'province.max' => 'The province/state cannot exceed 255 characters.',
            'country.required' => 'The country is required.',
            'country.max' => 'The country cannot exceed 255 characters.',
            'zip.required' => 'The zip/postal code is required.',
            'zip.max' => 'The zip/postal code cannot exceed 20 characters.',
            'phone.regex' => 'Please provide a valid phone number in international format (e.g., +1234567890).',
            'first_name.max' => 'The first name cannot exceed 255 characters.',
            'last_name.max' => 'The last name cannot exceed 255 characters.',
        ]);
    }
}
