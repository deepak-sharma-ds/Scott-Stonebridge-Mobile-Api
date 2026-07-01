<?php

namespace Database\Factories;

use App\Models\FreeReadingSubmission;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FreeReadingSubmission>
 */
class FreeReadingSubmissionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => $this->faker->firstName(),
            'last_name' => $this->faker->lastName(),
            'email' => $this->faker->safeEmail(),
            'phone_country_code' => '+'.$this->faker->numberBetween(1, 99),
            'phone' => $this->faker->numerify('##########'),
        ];
    }
}
