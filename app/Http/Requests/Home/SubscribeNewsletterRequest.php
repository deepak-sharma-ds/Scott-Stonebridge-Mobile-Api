<?php

namespace App\Http\Requests\Home;

use App\Http\Requests\BaseApiRequest;

class SubscribeNewsletterRequest extends BaseApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'email.required' => 'The email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.max' => 'The email address cannot exceed 255 characters.',
        ]);
    }
}
