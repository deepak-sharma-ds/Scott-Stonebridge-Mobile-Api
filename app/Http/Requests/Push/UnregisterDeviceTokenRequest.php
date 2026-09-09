<?php

namespace App\Http\Requests\Push;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class UnregisterDeviceTokenRequest extends BaseApiRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'fcm_token' => ['required', 'string', 'max:512'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'fcm_token.required' => 'The FCM token is required.',
        ]);
    }
}
