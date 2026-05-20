<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiConversation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiConversation>
 */
class AiConversationFactory extends Factory
{
    protected $model = AiConversation::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_id' => (string) Str::uuid(),
            'shopify_customer_id' => null,
            'shop_domain' => fake()->domainName(),
            'page_type' => fake()->randomElement(['home', 'product', 'collection', 'cart', 'search', 'account']),
            'locale' => 'en',
            'status' => AiConversation::STATUS_ACTIVE,
            'metadata' => null,
            'started_at' => now(),
            'ended_at' => null,
        ];
    }

    public function ended(): self
    {
        return $this->state(fn () => [
            'status' => AiConversation::STATUS_ENDED,
            'ended_at' => now(),
        ]);
    }

    public function escalated(): self
    {
        return $this->state(fn () => [
            'status' => AiConversation::STATUS_ESCALATED,
        ]);
    }

    public function forCustomer(string $customerId): self
    {
        return $this->state(fn () => [
            'shopify_customer_id' => $customerId,
        ]);
    }
}
