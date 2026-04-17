<?php

namespace Tests\Unit\DTOs;

use App\DTOs\Order\OrderDTO;
use App\DTOs\Order\OrderLineItemDTO;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OrderDTOTest extends TestCase
{
    /**
     * Test that OrderDTO can be instantiated with valid data.
     */
    public function test_can_create_order_dto_with_valid_data(): void
    {
        $lineItem = new OrderLineItemDTO(
            title: 'Test Product',
            quantity: 2,
            discountedTotalPrice: ['amount' => '59.98', 'currency' => 'GBP'],
            variantId: 'gid://shopify/ProductVariant/456',
            variantTitle: 'Default',
            image: 'https://example.com/image.jpg',
            productTitle: 'Test Product',
            productHandle: 'test-product',
        );

        $dto = new OrderDTO(
            id: 'gid://shopify/Order/123',
            name: '#1001',
            orderNumber: 1001,
            processedAt: '2025-01-20T10:00:00Z',
            financialStatus: 'PAID',
            fulfillmentStatus: 'FULFILLED',
            totalPrice: ['amount' => '69.98', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '59.98', 'currency' => 'GBP'],
            totalTax: ['amount' => '10.00', 'currency' => 'GBP'],
            lineItems: [$lineItem],
            shippingAddress: [
                'name' => 'John Doe',
                'address1' => '123 Main St',
                'city' => 'London',
                'province' => 'England',
                'country' => 'United Kingdom',
                'zip' => 'SW1A 1AA',
            ],
        );

        $this->assertEquals('gid://shopify/Order/123', $dto->id);
        $this->assertEquals('#1001', $dto->name);
        $this->assertEquals(1001, $dto->orderNumber);
        $this->assertEquals('2025-01-20T10:00:00Z', $dto->processedAt);
        $this->assertEquals('PAID', $dto->financialStatus);
        $this->assertEquals('FULFILLED', $dto->fulfillmentStatus);
        $this->assertEquals('69.98', $dto->totalPrice['amount']);
        $this->assertEquals('GBP', $dto->totalPrice['currency']);
        $this->assertEquals('59.98', $dto->subtotalPrice['amount']);
        $this->assertEquals('10.00', $dto->totalTax['amount']);
        $this->assertCount(1, $dto->lineItems);
        $this->assertInstanceOf(OrderLineItemDTO::class, $dto->lineItems[0]);
        $this->assertIsArray($dto->shippingAddress);
        $this->assertEquals('John Doe', $dto->shippingAddress['name']);
    }

    /**
     * Test that OrderDTO throws exception when ID is empty.
     */
    public function test_throws_exception_when_id_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order ID is required');

        new OrderDTO(
            id: '',
            name: '#1001',
            orderNumber: 1001,
            processedAt: '2025-01-20T10:00:00Z',
            financialStatus: null,
            fulfillmentStatus: null,
            totalPrice: ['amount' => '0.00', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '0.00', 'currency' => 'GBP'],
            totalTax: ['amount' => '0.00', 'currency' => 'GBP'],
            lineItems: [],
            shippingAddress: null,
        );
    }

    /**
     * Test that OrderDTO throws exception when name is empty.
     */
    public function test_throws_exception_when_name_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order name is required');

        new OrderDTO(
            id: 'gid://shopify/Order/123',
            name: '',
            orderNumber: 1001,
            processedAt: '2025-01-20T10:00:00Z',
            financialStatus: null,
            fulfillmentStatus: null,
            totalPrice: ['amount' => '0.00', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '0.00', 'currency' => 'GBP'],
            totalTax: ['amount' => '0.00', 'currency' => 'GBP'],
            lineItems: [],
            shippingAddress: null,
        );
    }

    /**
     * Test that OrderDTO throws exception when order number is not positive.
     */
    public function test_throws_exception_when_order_number_is_not_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Order number must be positive');

        new OrderDTO(
            id: 'gid://shopify/Order/123',
            name: '#1001',
            orderNumber: 0,
            processedAt: '2025-01-20T10:00:00Z',
            financialStatus: null,
            fulfillmentStatus: null,
            totalPrice: ['amount' => '0.00', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '0.00', 'currency' => 'GBP'],
            totalTax: ['amount' => '0.00', 'currency' => 'GBP'],
            lineItems: [],
            shippingAddress: null,
        );
    }

    /**
     * Test that OrderDTO can be created with null optional fields.
     */
    public function test_can_create_order_with_null_optional_fields(): void
    {
        $dto = new OrderDTO(
            id: 'gid://shopify/Order/123',
            name: '#1001',
            orderNumber: 1001,
            processedAt: '2025-01-20T10:00:00Z',
            financialStatus: null,
            fulfillmentStatus: null,
            totalPrice: ['amount' => '0.00', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '0.00', 'currency' => 'GBP'],
            totalTax: ['amount' => '0.00', 'currency' => 'GBP'],
            lineItems: [],
            shippingAddress: null,
        );

        $this->assertNull($dto->financialStatus);
        $this->assertNull($dto->fulfillmentStatus);
        $this->assertNull($dto->shippingAddress);
        $this->assertEmpty($dto->lineItems);
    }

    /**
     * Test getTotalItems() returns correct sum of quantities.
     */
    public function test_get_total_items_returns_correct_sum(): void
    {
        $lineItem1 = new OrderLineItemDTO(
            title: 'Product 1',
            quantity: 2,
            discountedTotalPrice: ['amount' => '59.98', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $lineItem2 = new OrderLineItemDTO(
            title: 'Product 2',
            quantity: 3,
            discountedTotalPrice: ['amount' => '89.97', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $dto = new OrderDTO(
            id: 'gid://shopify/Order/123',
            name: '#1001',
            orderNumber: 1001,
            processedAt: '2025-01-20T10:00:00Z',
            financialStatus: 'PAID',
            fulfillmentStatus: 'FULFILLED',
            totalPrice: ['amount' => '149.95', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '149.95', 'currency' => 'GBP'],
            totalTax: ['amount' => '0.00', 'currency' => 'GBP'],
            lineItems: [$lineItem1, $lineItem2],
            shippingAddress: null,
        );

        $this->assertEquals(5, $dto->getTotalItems());
    }

    /**
     * Test getTotalItems() returns zero for empty order.
     */
    public function test_get_total_items_returns_zero_for_empty_order(): void
    {
        $dto = new OrderDTO(
            id: 'gid://shopify/Order/123',
            name: '#1001',
            orderNumber: 1001,
            processedAt: '2025-01-20T10:00:00Z',
            financialStatus: null,
            fulfillmentStatus: null,
            totalPrice: ['amount' => '0.00', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '0.00', 'currency' => 'GBP'],
            totalTax: ['amount' => '0.00', 'currency' => 'GBP'],
            lineItems: [],
            shippingAddress: null,
        );

        $this->assertEquals(0, $dto->getTotalItems());
    }

    /**
     * Test fromShopifyResponse() creates DTO from Shopify data with edges format.
     */
    public function test_from_shopify_response_creates_dto_with_edges_format(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Order/123',
            'name' => '#1001',
            'orderNumber' => 1001,
            'processedAt' => '2025-01-20T10:00:00Z',
            'financialStatus' => 'PAID',
            'fulfillmentStatus' => 'FULFILLED',
            'totalPriceV2' => [
                'amount' => '69.98',
                'currencyCode' => 'GBP',
            ],
            'subtotalPriceV2' => [
                'amount' => '59.98',
                'currencyCode' => 'GBP',
            ],
            'totalTaxV2' => [
                'amount' => '10.00',
                'currencyCode' => 'GBP',
            ],
            'lineItems' => [
                'edges' => [
                    [
                        'node' => [
                            'title' => 'Test Product',
                            'quantity' => 2,
                            'discountedTotalPrice' => [
                                'amount' => '59.98',
                                'currencyCode' => 'GBP',
                            ],
                            'variant' => [
                                'id' => 'gid://shopify/ProductVariant/456',
                                'title' => 'Default',
                                'image' => [
                                    'url' => 'https://example.com/image.jpg',
                                ],
                                'product' => [
                                    'title' => 'Test Product',
                                    'handle' => 'test-product',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'shippingAddress' => [
                'name' => 'John Doe',
                'address1' => '123 Main St',
                'city' => 'London',
                'province' => 'England',
                'country' => 'United Kingdom',
                'zip' => 'SW1A 1AA',
            ],
        ];

        $dto = OrderDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/Order/123', $dto->id);
        $this->assertEquals('#1001', $dto->name);
        $this->assertEquals(1001, $dto->orderNumber);
        $this->assertEquals('PAID', $dto->financialStatus);
        $this->assertEquals('FULFILLED', $dto->fulfillmentStatus);
        $this->assertEquals('69.98', $dto->totalPrice['amount']);
        $this->assertEquals('GBP', $dto->totalPrice['currency']);
        $this->assertCount(1, $dto->lineItems);
        $this->assertInstanceOf(OrderLineItemDTO::class, $dto->lineItems[0]);
        $this->assertEquals(2, $dto->getTotalItems());
        $this->assertIsArray($dto->shippingAddress);
        $this->assertEquals('John Doe', $dto->shippingAddress['name']);
    }

    /**
     * Test fromShopifyResponse() creates DTO from Shopify data without edges format.
     */
    public function test_from_shopify_response_creates_dto_without_edges_format(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Order/123',
            'name' => '#1001',
            'orderNumber' => 1001,
            'processedAt' => '2025-01-20T10:00:00Z',
            'totalPriceV2' => [
                'amount' => '29.99',
                'currencyCode' => 'GBP',
            ],
            'subtotalPriceV2' => [
                'amount' => '29.99',
                'currencyCode' => 'GBP',
            ],
            'totalTaxV2' => [
                'amount' => '0.00',
                'currencyCode' => 'GBP',
            ],
            'lineItems' => [
                [
                    'title' => 'Test Product',
                    'quantity' => 1,
                    'discountedTotalPrice' => [
                        'amount' => '29.99',
                        'currencyCode' => 'GBP',
                    ],
                ],
            ],
        ];

        $dto = OrderDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/Order/123', $dto->id);
        $this->assertCount(1, $dto->lineItems);
        $this->assertEquals(1, $dto->getTotalItems());
    }

    /**
     * Test fromShopifyResponse() handles alternative price field names.
     */
    public function test_from_shopify_response_handles_alternative_price_fields(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Order/123',
            'name' => '#1001',
            'orderNumber' => 1001,
            'processedAt' => '2025-01-20T10:00:00Z',
            'totalPrice' => [
                'amount' => '69.98',
                'currencyCode' => 'USD',
            ],
            'subtotalPrice' => [
                'amount' => '59.98',
                'currencyCode' => 'USD',
            ],
            'totalTax' => [
                'amount' => '10.00',
                'currencyCode' => 'USD',
            ],
            'lineItems' => [],
        ];

        $dto = OrderDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('69.98', $dto->totalPrice['amount']);
        $this->assertEquals('USD', $dto->totalPrice['currency']);
        $this->assertEquals('59.98', $dto->subtotalPrice['amount']);
        $this->assertEquals('USD', $dto->subtotalPrice['currency']);
        $this->assertEquals('10.00', $dto->totalTax['amount']);
        $this->assertEquals('USD', $dto->totalTax['currency']);
    }

    /**
     * Test fromShopifyResponse() handles missing optional fields.
     */
    public function test_from_shopify_response_handles_missing_optional_fields(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Order/123',
            'name' => '#1001',
            'orderNumber' => 1001,
            'processedAt' => '2025-01-20T10:00:00Z',
        ];

        $dto = OrderDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/Order/123', $dto->id);
        $this->assertNull($dto->financialStatus);
        $this->assertNull($dto->fulfillmentStatus);
        $this->assertEquals('0.00', $dto->totalPrice['amount']);
        $this->assertEquals('GBP', $dto->totalPrice['currency']);
        $this->assertEmpty($dto->lineItems);
        $this->assertNull($dto->shippingAddress);
    }

    /**
     * Test toArray() converts DTO to array including nested line items.
     */
    public function test_to_array_converts_dto_to_array_with_nested_line_items(): void
    {
        $lineItem = new OrderLineItemDTO(
            title: 'Test Product',
            quantity: 2,
            discountedTotalPrice: ['amount' => '59.98', 'currency' => 'GBP'],
            variantId: 'gid://shopify/ProductVariant/456',
            variantTitle: 'Default',
            image: 'https://example.com/image.jpg',
            productTitle: 'Test Product',
            productHandle: 'test-product',
        );

        $dto = new OrderDTO(
            id: 'gid://shopify/Order/123',
            name: '#1001',
            orderNumber: 1001,
            processedAt: '2025-01-20T10:00:00Z',
            financialStatus: 'PAID',
            fulfillmentStatus: 'FULFILLED',
            totalPrice: ['amount' => '69.98', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '59.98', 'currency' => 'GBP'],
            totalTax: ['amount' => '10.00', 'currency' => 'GBP'],
            lineItems: [$lineItem],
            shippingAddress: [
                'name' => 'John Doe',
                'address1' => '123 Main St',
            ],
        );

        $array = $dto->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('gid://shopify/Order/123', $array['id']);
        $this->assertEquals('#1001', $array['name']);
        $this->assertEquals(1001, $array['orderNumber']);
        $this->assertIsArray($array['lineItems']);
        $this->assertCount(1, $array['lineItems']);
        $this->assertIsArray($array['lineItems'][0]);
        $this->assertEquals('Test Product', $array['lineItems'][0]['title']);
        $this->assertEquals(2, $array['lineItems'][0]['quantity']);
        $this->assertIsArray($array['totalPrice']);
        $this->assertEquals('69.98', $array['totalPrice']['amount']);
        $this->assertIsArray($array['shippingAddress']);
        $this->assertEquals('John Doe', $array['shippingAddress']['name']);
    }

    /**
     * Test toArray() handles null optional fields.
     */
    public function test_to_array_handles_null_optional_fields(): void
    {
        $dto = new OrderDTO(
            id: 'gid://shopify/Order/123',
            name: '#1001',
            orderNumber: 1001,
            processedAt: '2025-01-20T10:00:00Z',
            financialStatus: null,
            fulfillmentStatus: null,
            totalPrice: ['amount' => '0.00', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '0.00', 'currency' => 'GBP'],
            totalTax: ['amount' => '0.00', 'currency' => 'GBP'],
            lineItems: [],
            shippingAddress: null,
        );

        $array = $dto->toArray();

        $this->assertNull($array['financialStatus']);
        $this->assertNull($array['fulfillmentStatus']);
        $this->assertNull($array['shippingAddress']);
        $this->assertIsArray($array['lineItems']);
        $this->assertEmpty($array['lineItems']);
    }
}
