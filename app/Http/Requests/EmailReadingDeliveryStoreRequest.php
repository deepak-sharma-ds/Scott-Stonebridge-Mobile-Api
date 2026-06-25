<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EmailReadingDeliveryStoreRequest extends FormRequest
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
            'email_reading_product_id' => ['required', 'integer', 'exists:email_reading_products,id'],
            'customer_email' => ['required', 'email', 'max:255'],
            'customer_name' => ['nullable', 'string', 'max:255'],
            'answers' => ['nullable', 'array'],
            'answers.*' => ['nullable', 'string'],
            'scheduled_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'email_reading_product_id.required' => 'Select a reading product.',
            'email_reading_product_id.exists' => 'The selected reading product is invalid.',
            'customer_email.required' => 'A customer email is required.',
        ];
    }
}
