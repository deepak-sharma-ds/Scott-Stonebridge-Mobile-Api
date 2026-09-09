<?php

namespace Database\Factories;

use App\Models\KlaviyoCampaignSweep;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<KlaviyoCampaignSweep>
 */
class KlaviyoCampaignSweepFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'campaign_id' => 'CAMP'.$this->faker->unique()->numberBetween(1000, 9999),
            'campaign_name' => $this->faker->sentence(3),
            'send_time' => now()->subHour(),
            'status' => KlaviyoCampaignSweep::STATUS_PENDING,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => KlaviyoCampaignSweep::STATUS_COMPLETED,
            'swept_at' => now(),
        ]);
    }
}
