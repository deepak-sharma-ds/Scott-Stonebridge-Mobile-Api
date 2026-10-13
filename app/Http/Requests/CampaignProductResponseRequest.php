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
            'formatting_contract' => ['nullable', 'string'],
            'body' => ['required', 'string'],
            'model_used' => ['nullable', 'string'],
            'prompt_tokens' => ['nullable', 'integer'],
            'completion_tokens' => ['nullable', 'integer'],
            'header_image' => ['nullable', 'image', 'max:5120'],
            'email_content' => ['nullable', 'string'],
            'email_footer' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array{'body.required': string}
     */
    public function messages(): array
    {
        return [
            'body.required' => 'The response body cannot be empty.',
        ];
    }
}
