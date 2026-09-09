<?php

namespace Database\Factories;

use App\Models\KlaviyoWebhookEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KlaviyoWebhookEvent>
 */
class KlaviyoWebhookEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = strtolower($this->faker->safeEmail());
        $flowId = 'FLOW'.$this->faker->numberBetween(100, 999);

        return [
            'event_key' => sha1($this->faker->unique()->uuid()),
            'flow_id' => $flowId,
            'recipient_email' => $email,
            'payload' => json_encode(['email' => $email, 'flow_id' => $flowId]),
            'received_at' => now(),
        ];
    }
}
