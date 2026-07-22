<?php

namespace App\Http\Requests;

use App\Models\CampaignProductResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CampaignProductResponseRequest extends FormRequest
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
            'source' => ['required', Rule::in([CampaignProductResponse::SOURCE_AI, CampaignProductResponse::SOURCE_MANUAL])],
            'prompt_template' => ['nullable', 'string'],
            'body' => ['required_if:source,'.CampaignProductResponse::SOURCE_MANUAL, 'nullable', 'string'],
        ];
    }
}
