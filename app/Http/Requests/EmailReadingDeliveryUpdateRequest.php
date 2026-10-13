<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmailReadingDeliveryUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string,mixed>
     */
    public function rules(): array
    {
        return [
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'scheduled_at' => ['nullable', 'date'],
            'expedited_at' => ['nullable', 'date'],
            'ai_response' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'customer_email.required' => 'A customer email is required.',
            'customer_email.email' => 'Enter a valid customer email.',
        ];
    }
}
