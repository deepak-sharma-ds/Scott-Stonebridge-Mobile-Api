<?php

declare(strict_types=1);

namespace Tests\Unit\DTOs\AI;

use App\DTOs\AI\OrderTrackingDTO;
use Tests\TestCase;

class OrderTrackingDTOTest extends TestCase
{
    public function test_calculate_expected_delivery_for_same_day_guarantee(): void
    {
        $createdAt = '2026-10-01T14:30:00Z';
        $title = 'SAME DAY GUARANTEE - Via Email';

        $delivery = OrderTrackingDTO::calculateExpectedDelivery($title, $createdAt, 'processing');

        $this->assertNotNull($delivery);
        $this->assertStringContainsString('Within 24 hours', $delivery);
        $this->assertStringContainsString('Fri, 2 Oct', $delivery);
        $this->assertStringContainsString('2:30 PM', $delivery);
    }

    public function test_calculate_expected_delivery_for_day_range(): void
    {
        $createdAt = '2026-10-01T10:00:00Z';
        $title = 'Standard 7 - 10 days - Via Email';

        $delivery = OrderTrackingDTO::calculateExpectedDelivery($title, $createdAt, 'processing');

        $this->assertNotNull($delivery);
        $this->assertStringContainsString('7–10 days', $delivery);
        $this->assertStringContainsString('8 Oct – 11 Oct 2026', $delivery);
    }

    public function test_calculate_expected_delivery_for_single_day(): void
    {
        $createdAt = '2026-10-01T10:00:00Z';
        $title = 'Express 2 days delivery';

        $delivery = OrderTrackingDTO::calculateExpectedDelivery($title, $createdAt, 'processing');

        $this->assertNotNull($delivery);
        $this->assertSame('By 3 Oct 2026', $delivery);
    }

    public function test_calculate_expected_delivery_fallback_baseline(): void
    {
        $createdAt = '2026-10-01T10:00:00Z';
        $title = 'Free Shipping';

        $delivery = OrderTrackingDTO::calculateExpectedDelivery($title, $createdAt, 'processing');

        $this->assertNotNull($delivery);
        $this->assertStringContainsString('3–7 days', $delivery);
        $this->assertStringContainsString('4 Oct – 8 Oct 2026', $delivery);
    }

    public function test_calculate_expected_delivery_delivered_status(): void
    {
        $createdAt = '2026-09-20T10:00:00Z';
        $title = 'SAME DAY GUARANTEE';

        $delivery = OrderTrackingDTO::calculateExpectedDelivery($title, $createdAt, 'delivered');

        $this->assertSame('Delivered', $delivery);
    }

    public function test_calculate_expected_delivery_cancelled_status(): void
    {
        $createdAt = '2026-09-20T10:00:00Z';
        $title = 'Standard 7 - 10 days';

        $delivery = OrderTrackingDTO::calculateExpectedDelivery($title, $createdAt, 'cancelled');

        $this->assertNull($delivery);
    }

    public function test_from_shopify_node_populates_created_at_and_estimated_delivery(): void
    {
        $node = [
            'name' => '#1099',
            'displayFulfillmentStatus' => 'UNFULFILLED',
            'displayFinancialStatus' => 'PAID',
            'createdAt' => '2026-10-01T12:00:00Z',
            'shippingLine' => [
                'title' => 'SAME DAY GUARANTEE - Via Email',
            ],
            'shippingAddress' => [
                'city' => 'Manchester',
            ],
            'lineItems' => [
                'edges' => [
                    [
                        'node' => [
                            'title' => 'Spirit Guide Reading',
                            'quantity' => 1,
                            'originalUnitPriceSet' => [
                                'shopMoney' => [
                                    'amount' => '35.00',
                                    'currencyCode' => 'GBP',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $dto = OrderTrackingDTO::fromShopifyNode($node);

        $this->assertSame('1099', $dto->orderNumber);
        $this->assertSame('2026-10-01T12:00:00Z', $dto->createdAt);
        $this->assertSame('SAME DAY GUARANTEE - Via Email', $dto->shippingTitle);
        $this->assertNotNull($dto->estimatedDelivery);
        $this->assertStringContainsString('Within 24 hours', $dto->estimatedDelivery);

        $array = $dto->toArray();
        $this->assertSame('2026-10-01T12:00:00Z', $array['created_at']);
        $this->assertSame('SAME DAY GUARANTEE - Via Email', $array['shipping_title']);
        $this->assertStringContainsString('Within 24 hours', $array['estimated_delivery']);
    }
}
