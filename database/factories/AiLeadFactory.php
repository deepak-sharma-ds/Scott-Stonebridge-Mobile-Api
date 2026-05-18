<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\AiLead;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AiLead>
 */
class AiLeadFactory extends Factory
{
    protected $model = AiLead::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_id' => (string) Str::uuid(),
            'shop_domain' => fake()->domainName(),
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'issue_summary' => null,
            'cart_snapshot_json' => null,
            'source' => AiLead::SOURCE_MANUAL_INPUT,
            'status' => AiLead::STATUS_NEW,
            'recovery_sent_at' => null,
        ];
    }

    public function forSession(string $sessionId): self
    {
        return $this->state(fn () => ['session_id' => $sessionId]);
    }

    public function withCart(int $itemCount = 2, float $totalPrice = 49.99): self
    {
        return $this->state(fn () => [
            'cart_snapshot_json' => [
                'items' => array_fill(0, $itemCount, ['quantity' => 1]),
                'item_count' => $itemCount,
                'total_price' => $totalPrice,
            ],
        ]);
    }

    public function recoverySent(): self
    {
        return $this->state(fn () => [
            'status' => AiLead::STATUS_RECOVERY_SENT,
            'recovery_sent_at' => now(),
        ]);
    }

    public function converted(): self
    {
        return $this->state(fn () => ['status' => AiLead::STATUS_CONVERTED]);
    }

    public function fromProactiveTrigger(): self
    {
        return $this->state(fn () => ['source' => AiLead::SOURCE_PROACTIVE_TRIGGER]);
    }

    public function fromEscalation(): self
    {
        return $this->state(fn () => ['source' => AiLead::SOURCE_ESCALATION]);
    }
}
