<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\ShopSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * ShopSetting — per-shop overrides for locale + welcome + free-shipping.
 *
 * @property int $id
 * @property string $shop_domain
 * @property string|null $default_locale_override
 * @property array<int, string>|null $allowed_locales_json
 * @property array<string, string>|null $welcome_messages_json
 * @property float|null $free_shipping_threshold
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class ShopSetting extends Model
{
    /** @use HasFactory<ShopSettingFactory> */
    use HasFactory;

    protected $fillable = [
        'shop_domain',
        'default_locale_override',
        'allowed_locales_json',
        'welcome_messages_json',
        'free_shipping_threshold',
    ];

    protected function casts(): array
    {
        return [
            'allowed_locales_json' => 'array',
            'welcome_messages_json' => 'array',
            'free_shipping_threshold' => 'decimal:2',
        ];
    }

    /**
     * Welcome message for a given locale; null when none configured.
     */
    public function welcomeFor(string $locale): ?string
    {
        $messages = $this->welcome_messages_json ?? [];

        return is_string($messages[$locale] ?? null) ? $messages[$locale] : null;
    }

    /**
     * Is the given locale in the allow-list? Returns true when no allow-list
     * is set (open by default).
     */
    public function locallyAllowed(string $locale): bool
    {
        $allow = $this->allowed_locales_json;
        if ($allow === null || $allow === []) {
            return true;
        }

        return in_array($locale, $allow, true);
    }
}
