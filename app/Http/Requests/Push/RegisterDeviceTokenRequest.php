<?php

namespace App\Http\Requests\Push;

use App\Http\Requests\BaseApiRequest;
use App\Models\DeviceToken;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

class RegisterDeviceTokenRequest extends BaseApiRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fcm_token' => ['required', 'string', 'max:512'],
            'platform' => ['required', 'string', Rule::in([DeviceToken::PLATFORM_IOS, DeviceToken::PLATFORM_ANDROID])],
            'device_id' => ['nullable', 'string', 'max:255'],
            'app_version' => ['nullable', 'string', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'fcm_token.required' => 'The FCM token is required.',
            'platform.in' => 'The platform must be ios or android.',
        ]);
    }
}
