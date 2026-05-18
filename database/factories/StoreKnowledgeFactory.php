<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\StoreKnowledge;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StoreKnowledge>
 */
class StoreKnowledgeFactory extends Factory
{
    protected $model = StoreKnowledge::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_domain' => fake()->domainName(),
            'content_type' => StoreKnowledge::TYPE_PAGE,
            'title' => fake()->sentence(4),
            'handle' => fake()->slug(),
            'summary' => fake()->paragraph(2),
            'raw_content' => fake()->paragraphs(3, true),
            'last_synced_at' => now(),
            'shopify_updated_at' => now()->subDay(),
        ];
    }

    public function forShop(string $shopDomain): self
    {
        return $this->state(fn () => ['shop_domain' => $shopDomain]);
    }

    public function policy(): self
    {
        return $this->state(fn () => ['content_type' => StoreKnowledge::TYPE_POLICY]);
    }

    public function blog(): self
    {
        return $this->state(fn () => ['content_type' => StoreKnowledge::TYPE_BLOG]);
    }

    public function faq(): self
    {
        return $this->state(fn () => ['content_type' => StoreKnowledge::TYPE_FAQ]);
    }

    public function ofType(string $type): self
    {
        return $this->state(fn () => ['content_type' => $type]);
    }
}
