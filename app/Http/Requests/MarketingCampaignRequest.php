<?php

namespace App\Http\Requests;

use App\Models\MarketingCampaign;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MarketingCampaignRequest extends FormRequest
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
        $campaignId = $this->route('marketing_campaign')?->id;

        return [
            'campaign_key' => [
                'required', 'string', 'max:255', 'regex:/^[a-z0-9-]+$/',
                Rule::unique('marketing_campaigns', 'campaign_key')->ignore($campaignId),
            ],
            'name' => ['required', 'string', 'max:255'],
            'klaviyo_campaign_id' => ['nullable', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(array_keys(MarketingCampaign::statusLabels()))],
        ];
    }

    /**
     * Derive campaign_key from name when none was supplied.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'campaign_key' => Str::slug($this->input('campaign_key') ?: $this->input('name', '')),
        ]);
    }

    /**
     * @return array<string,string>
     */
    public function messages(): array
    {
        return [
            'campaign_key.unique' => 'A campaign with this key already exists.',
            'campaign_key.regex' => 'Campaign key may only contain lowercase letters, numbers and dashes.',
        ];
    }
}
