<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Http\Requests\BaseApiRequest;
use App\Rules\NotSpamText;

class EscalateRequest extends BaseApiRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'session_id' => ['required', 'string', 'uuid', 'exists:ai_conversations,session_id'],
            'reason' => ['required', 'string', 'min:5', 'max:500', new NotSpamText],
            'customer_name' => ['required', 'string', 'min:2', 'max:255', new NotSpamText],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'customer_phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^\+?[1-9]\d{1,14}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'reason.min' => 'Please describe your issue in a bit more detail.',
            'customer_name.required' => 'Your name is required.',
            'customer_phone.regex' => 'Please provide a valid phone number in international format (e.g., +1234567890).',
        ]);
    }
}
