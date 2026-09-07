<?php

namespace Database\Factories;

use App\Models\DeviceToken;
use App\Models\PushNotification;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PushNotification>
 */
class PushNotificationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_type' => PushNotification::SOURCE_FLOW,
            'source_id' => 'FLOW'.$this->faker->numberBetween(100, 999),
            'message_id' => 'MSG'.$this->faker->numberBetween(100, 999),
            'recipient_email' => strtolower($this->faker->safeEmail()),
            'device_token_id' => DeviceToken::factory(),
            'title' => $this->faker->sentence(3),
            'body' => $this->faker->sentence(8),
            'data' => ['deep_link' => 'app://home'],
            'status' => PushNotification::STATUS_PENDING,
        ];
    }

    public function sent(): static
    {
        return $this->state(fn () => [
            'status' => PushNotification::STATUS_SENT,
            'fcm_message_id' => 'projects/test/messages/'.$this->faker->uuid(),
            'sent_at' => now(),
        ]);
    }
}
