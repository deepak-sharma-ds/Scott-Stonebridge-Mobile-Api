<?php

namespace App\Http\Requests\Push;

use App\Http\Requests\BaseApiRequest;
use Illuminate\Contracts\Validation\ValidationRule;

class UpdatePushPreferencesRequest extends BaseApiRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'enabled.required' => 'The enabled flag is required.',
            'enabled.boolean' => 'The enabled flag must be true or false.',
        ]);
    }
}
