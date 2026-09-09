<?php

namespace App\Http\Requests;

use App\Models\CampaignDelivery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CampaignDeliveryUpdateRequest extends FormRequest
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
            'campaign_product_id' => ['nullable', 'integer', 'exists:campaign_products,id'],
            'scheduled_at' => ['nullable', 'date'],
            'expedited_at' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(array_keys(CampaignDelivery::statusLabels()))],
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
            'campaign_product_id.exists' => 'The selected campaign product is invalid.',
        ];
    }
}
