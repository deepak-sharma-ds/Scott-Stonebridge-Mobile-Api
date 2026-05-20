<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\TriggerRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TriggerRule>
 */
class TriggerRuleFactory extends Factory
{
    protected $model = TriggerRule::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'shop_domain' => fake()->domainName(),
            'page_type' => TriggerRule::PAGE_ALL,
            'trigger_type' => TriggerRule::TYPE_EXIT_INTENT,
            'trigger_value' => null,
            'message_template' => 'Still deciding? Our team is here to help.',
            'is_active' => true,
            'priority' => 10,
        ];
    }

    public function inactive(): self
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function forShop(string $shopDomain): self
    {
        return $this->state(fn () => ['shop_domain' => $shopDomain]);
    }

    public function forPage(string $pageType): self
    {
        return $this->state(fn () => ['page_type' => $pageType]);
    }

    public function exitIntent(): self
    {
        return $this->state(fn () => [
            'trigger_type' => TriggerRule::TYPE_EXIT_INTENT,
            'trigger_value' => null,
        ]);
    }

    public function timeOnPage(int $seconds): self
    {
        return $this->state(fn () => [
            'trigger_type' => TriggerRule::TYPE_TIME_ON_PAGE,
            'trigger_value' => $seconds,
        ]);
    }

    public function scrollDepth(int $percent): self
    {
        return $this->state(fn () => [
            'trigger_type' => TriggerRule::TYPE_SCROLL_DEPTH,
            'trigger_value' => $percent,
        ]);
    }

    public function cartAbandonment(): self
    {
        return $this->state(fn () => [
            'trigger_type' => TriggerRule::TYPE_CART_ABANDONMENT,
            'trigger_value' => null,
        ]);
    }

    public function priority(int $priority): self
    {
        return $this->state(fn () => ['priority' => $priority]);
    }
}
