<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI\MCP\Mappers;

use App\Services\AI\MCP\Mappers\OrderMapper;
use Tests\TestCase;

class OrderMapperTest extends TestCase
{
    public function test_maps_order_status_with_tracking(): void
    {
        $result = [
            'order' => [
                'name' => '#1234',
                'fulfillment_status' => 'IN_TRANSIT',
                'financial_status' => 'PAID',
                'tracking' => [
                    'number' => '1Z999',
                    'url' => 'https://ups.example/track',
                    'company' => 'UPS',
                ],
                'estimated_delivery' => '2026-05-22',
                'ship_to' => ['city' => 'London'],
            ],
        ];

        $dto = OrderMapper::fromOrderStatus($result);

        $this->assertNotNull($dto);
        $this->assertSame('1234', $dto->orderNumber);
        $this->assertSame('in_transit', $dto->status);
        $this->assertSame('1Z999', $dto->trackingNumber);
        $this->assertSame('UPS', $dto->carrier);
        $this->assertSame('London', $dto->shipToCity);
    }

    public function test_maps_voided_to_cancelled(): void
    {
        $dto = OrderMapper::fromOrderStatus([
            'order' => ['order_number' => '7', 'financial_status' => 'VOIDED'],
        ]);

        $this->assertNotNull($dto);
        $this->assertSame('cancelled', $dto->status);
    }

    public function test_order_list_filters_invalid_rows(): void
    {
        $list = OrderMapper::fromOrderList([
            'orders' => [
                ['name' => '#1001', 'fulfillment_status' => 'FULFILLED', 'financial_status' => 'PAID', 'total' => '12.50', 'currency' => 'GBP', 'created_at' => '2026-01-02'],
                ['fulfillment_status' => 'UNFULFILLED'], // missing number → drop
                ['name' => '1002', 'fulfillment_status' => 'IN_TRANSIT'],
            ],
        ]);

        $this->assertCount(2, $list);
        $this->assertSame('1001', $list[0]->orderNumber);
        $this->assertSame('delivered', $list[0]->status);
        $this->assertSame(1250, $list[0]->totalMinorUnits);
        $this->assertSame('1002', $list[1]->orderNumber);
    }
}
