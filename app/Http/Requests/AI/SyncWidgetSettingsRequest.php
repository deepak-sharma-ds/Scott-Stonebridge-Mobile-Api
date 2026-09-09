<?php

declare(strict_types=1);

namespace App\Http\Requests\AI;

use App\Http\Requests\BaseApiRequest;

/**
 * Validates payload for standalone widget theme settings synchronization.
 */
class SyncWidgetSettingsRequest extends BaseApiRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'shop_domain' => ['required', 'string', 'max:255'],
            'theme_settings' => ['required', 'array'],
            'theme_settings.persona_name' => ['sometimes', 'nullable', 'string', 'max:80'],
            'theme_settings.avatar_url' => ['sometimes', 'nullable', 'string', 'max:500'],
            'theme_settings.brand_color' => ['sometimes', 'nullable', 'string', 'max:30'],
            'theme_settings.widget_position' => ['sometimes', 'nullable', 'string', 'max:30'],
            'theme_settings.greeting_message' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'theme_settings.free_shipping_threshold' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'locale' => ['sometimes', 'nullable', 'string', 'max:10'],
        ];
    }
}
