<?php

namespace Database\Factories;

use App\Models\DeviceToken;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeviceToken>
 */
class DeviceTokenFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shopify_customer_id' => $this->faker->numberBetween(1000000, 9999999),
            'customer_email' => strtolower($this->faker->unique()->safeEmail()),
            'fcm_token' => 'fcm-'.$this->faker->unique()->sha256(),
            'platform' => $this->faker->randomElement([DeviceToken::PLATFORM_IOS, DeviceToken::PLATFORM_ANDROID]),
            'device_id' => $this->faker->uuid(),
            'app_version' => '1.0.0',
            'push_enabled' => true,
            'last_seen_at' => now(),
            'revoked_at' => null,
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()]);
    }

    public function optedOut(): static
    {
        return $this->state(fn () => ['push_enabled' => false]);
    }
}
