<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CampaignProductRequest extends FormRequest
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
        $campaign = $this->route('marketingCampaign');

        return [
            'shopify_product_id' => [
                'required', 'integer', 'min:1',
                Rule::unique('campaign_products', 'shopify_product_id')
                    ->where('marketing_campaign_id', $campaign?->id),
            ],
            'product_title' => ['nullable', 'string', 'max:255'],
            'prompt_template' => ['nullable', 'string'],
            'email_subject' => ['nullable', 'string', 'max:255'],
            'model' => ['nullable', 'string', 'max:255'],
            'max_tokens' => ['nullable', 'integer', 'min:1', 'max:8000'],
        ];
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'shopify_product_id.unique' => 'This product is already linked to this campaign.',
        ];
    }
}
