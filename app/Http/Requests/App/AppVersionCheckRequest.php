<?php

namespace App\Http\Requests\App;

use App\Http\Requests\BaseApiRequest;
use App\Models\DeviceToken;
use Illuminate\Validation\Rule;

/**
 * App Version Check Request
 *
 * Validates platform ('ios'|'android') and semantic version input.
 */
class AppVersionCheckRequest extends BaseApiRequest
{
    /**
     * Prepare inputs for validation.
     */
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        $merges = [];

        if ($this->has('platform') && is_string($this->input('platform'))) {
            $merges['platform'] = strtolower(trim($this->input('platform')));
        }

        if (! $this->has('version') && $this->has('app_version')) {
            $merges['version'] = $this->input('app_version');
        }

        if ($this->has('version') && is_string($this->input('version'))) {
            $merges['version'] = trim($this->input('version'));
        }

        if (! empty($merges)) {
            $this->merge($merges);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'platform' => [
                'required',
                'string',
                Rule::in([DeviceToken::PLATFORM_IOS, DeviceToken::PLATFORM_ANDROID]),
            ],
            'version' => [
                'required',
                'string',
                'max:32',
                'regex:/^v?(0|[1-9]\d*)(\.(0|[1-9]\d*))+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$/i',
            ],
        ];
    }

    /**
     * Custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'platform.required' => 'The platform field is required.',
            'platform.in' => 'The platform must be either ios or android.',
            'version.required' => 'The version field is required.',
            'version.regex' => 'The version must be a valid semantic version (e.g., 1.0.0).',
        ]);
    }
}
