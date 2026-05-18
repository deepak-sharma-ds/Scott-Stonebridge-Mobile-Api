<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ConversionEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ConversionEvent>
 */
class ConversionEventFactory extends Factory
{
    protected $model = ConversionEvent::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_id' => (string) Str::uuid(),
            'shop_domain' => fake()->domainName(),
            'event_type' => ConversionEvent::EVENT_CHAT_OPENED,
            'product_id' => null,
            'order_id' => null,
            'revenue' => null,
            'metadata_json' => null,
            'created_at' => now(),
        ];
    }

    public function forSession(string $sessionId): self
    {
        return $this->state(fn () => ['session_id' => $sessionId]);
    }

    public function forShop(string $shopDomain): self
    {
        return $this->state(fn () => ['shop_domain' => $shopDomain]);
    }

    public function ofType(string $eventType): self
    {
        return $this->state(fn () => ['event_type' => $eventType]);
    }

    public function orderPlaced(string $orderId = 'gid://shopify/Order/1', float $revenue = 49.99): self
    {
        return $this->state(fn () => [
            'event_type' => ConversionEvent::EVENT_ORDER_PLACED,
            'order_id' => $orderId,
            'revenue' => $revenue,
        ]);
    }

    public function abandonRecoverySent(): self
    {
        return $this->state(fn () => ['event_type' => ConversionEvent::EVENT_ABANDON_RECOVERY_SENT]);
    }

    public function leadCaptured(): self
    {
        return $this->state(fn () => ['event_type' => ConversionEvent::EVENT_LEAD_CAPTURED]);
    }
}
