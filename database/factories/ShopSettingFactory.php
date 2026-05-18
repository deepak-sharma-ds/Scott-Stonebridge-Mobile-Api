<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ShopSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopSetting>
 */
class ShopSettingFactory extends Factory
{
    protected $model = ShopSetting::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_domain' => fake()->unique()->domainName(),
            'default_locale_override' => null,
            'allowed_locales_json' => null,
            'welcome_messages_json' => null,
            'free_shipping_threshold' => null,
        ];
    }

    public function forShop(string $shopDomain): self
    {
        return $this->state(fn () => ['shop_domain' => $shopDomain]);
    }

    public function withLocaleOverride(string $locale): self
    {
        return $this->state(fn () => ['default_locale_override' => $locale]);
    }

    /**
     * @param  list<string>  $allowed
     */
    public function withAllowedLocales(array $allowed): self
    {
        return $this->state(fn () => ['allowed_locales_json' => $allowed]);
    }

    /**
     * @param  array<string, string>  $messages
     */
    public function withWelcomeMessages(array $messages): self
    {
        return $this->state(fn () => ['welcome_messages_json' => $messages]);
    }

    public function withFreeShippingThreshold(float $threshold): self
    {
        return $this->state(fn () => ['free_shipping_threshold' => $threshold]);
    }
}
