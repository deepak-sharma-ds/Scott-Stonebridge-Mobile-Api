<?php

namespace App\Http\Requests\Contact;

use App\Http\Requests\BaseApiRequest;

class ContactFormRequest extends BaseApiRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'subject' => ['sometimes', 'nullable', 'string', 'max:255'],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'phone' => ['sometimes', 'nullable', 'string', 'regex:/^\+?[1-9]\d{1,14}$/'],
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.required' => 'Your name is required.',
            'name.max' => 'The name cannot exceed 255 characters.',
            'email.required' => 'Your email address is required.',
            'email.email' => 'Please provide a valid email address.',
            'email.max' => 'The email address cannot exceed 255 characters.',
            'subject.max' => 'The subject cannot exceed 255 characters.',
            'message.required' => 'A message is required.',
            'message.min' => 'The message must be at least 10 characters long.',
            'message.max' => 'The message cannot exceed 5000 characters.',
            'phone.regex' => 'Please provide a valid phone number in international format (e.g., +1234567890).',
        ]);
    }
}
