<?php

namespace Tests\Unit\Resources;

use App\DTOs\Order\OrderDTO;
use App\DTOs\Order\OrderLineItemDTO;
use App\Http\Resources\Order\OrderResource;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * OrderResource Unit Tests
 * 
 * Tests transformation logic from OrderDTO to API response format.
 * Validates field mapping, calculated fields, nested resource handling, and edge cases.
 */
class OrderResourceTest extends TestCase
{
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = Request::create('/test', 'GET');
    }

    /** @test */
    public function it_transforms_order_dto_to_array(): void
    {
        // Arrange
        $lineItem1 = new OrderLineItemDTO(
            title: 'Product 1',
            quantity: 2,
            discountedTotalPrice: ['amount' => '20.00', 'currency' => 'GBP'],
            variantId: 'gid://shopify/ProductVariant/1',
            variantTitle: 'Small',
            image: 'https://example.com/img1.jpg',
            productTitle: 'Product 1',
            productHandle: 'product-1',
        );

        $lineItem2 = new OrderLineItemDTO(
            title: 'Product 2',
            quantity: 3,
            discountedTotalPrice: ['amount' => '45.00', 'currency' => 'GBP'],
            variantId: 'gid://shopify/ProductVariant/2',
            variantTitle: 'Medium',
            image: 'https://example.com/img2.jpg',
            productTitle: 'Product 2',
            productHandle: 'product-2',
        );

        $orderDTO = new OrderDTO(
            id: 'gid://shopify/Order/123',
            name: '#1001',
            orderNumber: 1001,
            processedAt: '2025-01-20T10:00:00Z',
            financialStatus: 'PAID',
            fulfillmentStatus: 'FULFILLED',
            totalPrice: ['amount' => '75.00', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '65.00', 'currency' => 'GBP'],
            totalTax: ['amount' => '10.00', 'currency' => 'GBP'],
            lineItems: [$lineItem1, $lineItem2],
            shippingAddress: [
                'address1' => '123 Main St',
                'city' => 'London',
                'country' => 'United Kingdom',
                'zip' => 'SW1A 1AA',
            ],
        );

        // Act
        $resource = new OrderResource($orderDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('gid://shopify/Order/123', $result['id']);
        $this->assertEquals('#1001', $result['name']);
        $this->assertEquals(1001, $result['order_number']);
        $this->assertEquals('2025-01-20T10:00:00Z', $result['processed_at']);
        $this->assertEquals('PAID', $result['financial_status']);
        $this->assertEquals('FULFILLED', $result['fulfillment_status']);
        $this->assertEquals('75.00', $result['total_price']);
        $this->assertEquals('65.00', $result['subtotal_price']);
        $this->assertEquals('10.00', $result['total_tax']);
        $this->assertEquals('GBP', $result['currency']);
        $this->assertEquals(5, $result['total_items']); // 2 + 3
        $this->assertEquals(2, $result['unique_items']); // 2 line items
        $this->assertIsArray($result['shipping_address']);
        $this->assertEquals('123 Main St', $result['shipping_address']['address1']);
    }

    /** @test */
    public function it_transforms_nested_line_items_using_line_item_resource(): void
    {
        // Arrange
        $lineItem = new OrderLineItemDTO(
            title: 'Test Product',
            quantity: 1,
            discountedTotalPrice: ['amount' => '25.00', 'currency' => 'USD'],
            variantId: 'gid://shopify/ProductVariant/789',
            variantTitle: 'Default',
            image: 'https://example.com/test.jpg',
            productTitle: 'Test Product',
            productHandle: 'test-product',
        );

        $orderDTO = new OrderDTO(
            id: 'gid://shopify/Order/456',
            name: '#1002',
            orderNumber: 1002,
            processedAt: '2025-01-20T11:00:00Z',
            financialStatus: 'PENDING',
            fulfillmentStatus: 'UNFULFILLED',
            totalPrice: ['amount' => '25.00', 'currency' => 'USD'],
            subtotalPrice: ['amount' => '25.00', 'currency' => 'USD'],
            totalTax: ['amount' => '0.00', 'currency' => 'USD'],
            lineItems: [$lineItem],
            shippingAddress: null,
        );

        // Act
        $resource = new OrderResource($orderDTO);
        $result = $resource->toArray($this->request);

        // Assert - line_items is a ResourceCollection, resolve it to array
        $this->assertInstanceOf(\Illuminate\Http\Resources\Json\AnonymousResourceCollection::class, $result['line_items']);
        $lineItemsArray = $result['line_items']->resolve($this->request);
        
        $this->assertIsArray($lineItemsArray);
        $this->assertCount(1, $lineItemsArray);
        
        $lineItemResult = $lineItemsArray[0];
        $this->assertEquals('Test Product', $lineItemResult['title']);
        $this->assertEquals(1, $lineItemResult['quantity']);
        $this->assertEquals('25.00', $lineItemResult['price']);
        $this->assertEquals('USD', $lineItemResult['currency']);
    }

    /** @test */
    public function it_calculates_total_items_correctly(): void
    {
        // Arrange
        $lineItem1 = new OrderLineItemDTO(
            title: 'Product 1',
            quantity: 5,
            discountedTotalPrice: ['amount' => '50.00', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $lineItem2 = new OrderLineItemDTO(
            title: 'Product 2',
            quantity: 3,
            discountedTotalPrice: ['amount' => '30.00', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $lineItem3 = new OrderLineItemDTO(
            title: 'Product 3',
            quantity: 2,
            discountedTotalPrice: ['amount' => '20.00', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $orderDTO = new OrderDTO(
            id: 'gid://shopify/Order/789',
            name: '#1003',
            orderNumber: 1003,
            processedAt: '2025-01-20T12:00:00Z',
            financialStatus: 'PAID',
            fulfillmentStatus: 'FULFILLED',
            totalPrice: ['amount' => '100.00', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '100.00', 'currency' => 'GBP'],
            totalTax: ['amount' => '0.00', 'currency' => 'GBP'],
            lineItems: [$lineItem1, $lineItem2, $lineItem3],
            shippingAddress: null,
        );

        // Act
        $resource = new OrderResource($orderDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertEquals(10, $result['total_items']); // 5 + 3 + 2
        $this->assertEquals(3, $result['unique_items']); // 3 line items
    }

    /** @test */
    public function it_handles_order_with_null_optional_fields(): void
    {
        // Arrange
        $lineItem = new OrderLineItemDTO(
            title: 'Product',
            quantity: 1,
            discountedTotalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $orderDTO = new OrderDTO(
            id: 'gid://shopify/Order/null',
            name: '#1004',
            orderNumber: 1004,
            processedAt: '2025-01-20T13:00:00Z',
            financialStatus: null,
            fulfillmentStatus: null,
            totalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            totalTax: ['amount' => '0.00', 'currency' => 'GBP'],
            lineItems: [$lineItem],
            shippingAddress: null,
        );

        // Act
        $resource = new OrderResource($orderDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertNull($result['financial_status']);
        $this->assertNull($result['fulfillment_status']);
        $this->assertNull($result['shipping_address']);
    }

    /** @test */
    public function it_handles_different_currency_codes(): void
    {
        // Arrange - GBP
        $lineItemGBP = new OrderLineItemDTO(
            title: 'Product',
            quantity: 1,
            discountedTotalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $orderGBP = new OrderDTO(
            id: 'gid://shopify/Order/gbp',
            name: '#1005',
            orderNumber: 1005,
            processedAt: '2025-01-20T14:00:00Z',
            financialStatus: 'PAID',
            fulfillmentStatus: 'FULFILLED',
            totalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            totalTax: ['amount' => '0.00', 'currency' => 'GBP'],
            lineItems: [$lineItemGBP],
            shippingAddress: null,
        );

        // Arrange - USD
        $lineItemUSD = new OrderLineItemDTO(
            title: 'Product',
            quantity: 1,
            discountedTotalPrice: ['amount' => '15.00', 'currency' => 'USD'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $orderUSD = new OrderDTO(
            id: 'gid://shopify/Order/usd',
            name: '#1006',
            orderNumber: 1006,
            processedAt: '2025-01-20T15:00:00Z',
            financialStatus: 'PAID',
            fulfillmentStatus: 'FULFILLED',
            totalPrice: ['amount' => '15.00', 'currency' => 'USD'],
            subtotalPrice: ['amount' => '15.00', 'currency' => 'USD'],
            totalTax: ['amount' => '0.00', 'currency' => 'USD'],
            lineItems: [$lineItemUSD],
            shippingAddress: null,
        );

        // Act
        $resourceGBP = new OrderResource($orderGBP);
        $resourceUSD = new OrderResource($orderUSD);

        $resultGBP = $resourceGBP->toArray($this->request);
        $resultUSD = $resourceUSD->toArray($this->request);

        // Assert
        $this->assertEquals('GBP', $resultGBP['currency']);
        $this->assertEquals('USD', $resultUSD['currency']);
    }

    /** @test */
    public function it_separates_price_fields_and_currency(): void
    {
        // Arrange
        $lineItem = new OrderLineItemDTO(
            title: 'Product',
            quantity: 1,
            discountedTotalPrice: ['amount' => '100.00', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $orderDTO = new OrderDTO(
            id: 'gid://shopify/Order/price',
            name: '#1007',
            orderNumber: 1007,
            processedAt: '2025-01-20T16:00:00Z',
            financialStatus: 'PAID',
            fulfillmentStatus: 'FULFILLED',
            totalPrice: ['amount' => '120.00', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '100.00', 'currency' => 'GBP'],
            totalTax: ['amount' => '20.00', 'currency' => 'GBP'],
            lineItems: [$lineItem],
            shippingAddress: null,
        );

        // Act
        $resource = new OrderResource($orderDTO);
        $result = $resource->toArray($this->request);

        // Assert - total_price, subtotal_price, total_tax, and currency are separate fields
        $this->assertArrayHasKey('total_price', $result);
        $this->assertArrayHasKey('subtotal_price', $result);
        $this->assertArrayHasKey('total_tax', $result);
        $this->assertArrayHasKey('currency', $result);
        $this->assertEquals('120.00', $result['total_price']);
        $this->assertEquals('100.00', $result['subtotal_price']);
        $this->assertEquals('20.00', $result['total_tax']);
        $this->assertEquals('GBP', $result['currency']);
        
        // Verify they are not nested in objects
        $this->assertIsString($result['total_price']);
        $this->assertIsString($result['subtotal_price']);
        $this->assertIsString($result['total_tax']);
        $this->assertIsString($result['currency']);
    }

    /** @test */
    public function it_uses_snake_case_for_field_names(): void
    {
        // Arrange
        $lineItem = new OrderLineItemDTO(
            title: 'Product',
            quantity: 1,
            discountedTotalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $orderDTO = new OrderDTO(
            id: 'gid://shopify/Order/snake',
            name: '#1008',
            orderNumber: 1008,
            processedAt: '2025-01-20T17:00:00Z',
            financialStatus: 'PAID',
            fulfillmentStatus: 'FULFILLED',
            totalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            totalTax: ['amount' => '0.00', 'currency' => 'GBP'],
            lineItems: [$lineItem],
            shippingAddress: ['address1' => '123 Main St'],
        );

        // Act
        $resource = new OrderResource($orderDTO);
        $result = $resource->toArray($this->request);

        // Assert - verify snake_case field names
        $this->assertArrayHasKey('order_number', $result);
        $this->assertArrayHasKey('processed_at', $result);
        $this->assertArrayHasKey('financial_status', $result);
        $this->assertArrayHasKey('fulfillment_status', $result);
        $this->assertArrayHasKey('total_price', $result);
        $this->assertArrayHasKey('subtotal_price', $result);
        $this->assertArrayHasKey('total_tax', $result);
        $this->assertArrayHasKey('line_items', $result);
        $this->assertArrayHasKey('total_items', $result);
        $this->assertArrayHasKey('unique_items', $result);
        $this->assertArrayHasKey('shipping_address', $result);
        
        // Verify camelCase fields are NOT present
        $this->assertArrayNotHasKey('orderNumber', $result);
        $this->assertArrayNotHasKey('processedAt', $result);
        $this->assertArrayNotHasKey('financialStatus', $result);
        $this->assertArrayNotHasKey('fulfillmentStatus', $result);
        $this->assertArrayNotHasKey('totalPrice', $result);
        $this->assertArrayNotHasKey('subtotalPrice', $result);
        $this->assertArrayNotHasKey('totalTax', $result);
        $this->assertArrayNotHasKey('lineItems', $result);
        $this->assertArrayNotHasKey('totalItems', $result);
        $this->assertArrayNotHasKey('uniqueItems', $result);
        $this->assertArrayNotHasKey('shippingAddress', $result);
    }

    /** @test */
    public function it_includes_calculated_fields(): void
    {
        // Arrange
        $lineItem1 = new OrderLineItemDTO(
            title: 'Product 1',
            quantity: 4,
            discountedTotalPrice: ['amount' => '40.00', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $lineItem2 = new OrderLineItemDTO(
            title: 'Product 2',
            quantity: 6,
            discountedTotalPrice: ['amount' => '60.00', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $orderDTO = new OrderDTO(
            id: 'gid://shopify/Order/calc',
            name: '#1009',
            orderNumber: 1009,
            processedAt: '2025-01-20T18:00:00Z',
            financialStatus: 'PAID',
            fulfillmentStatus: 'FULFILLED',
            totalPrice: ['amount' => '100.00', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '100.00', 'currency' => 'GBP'],
            totalTax: ['amount' => '0.00', 'currency' => 'GBP'],
            lineItems: [$lineItem1, $lineItem2],
            shippingAddress: null,
        );

        // Act
        $resource = new OrderResource($orderDTO);
        $result = $resource->toArray($this->request);

        // Assert - calculated fields are present
        $this->assertArrayHasKey('total_items', $result);
        $this->assertArrayHasKey('unique_items', $result);
        $this->assertEquals(10, $result['total_items']); // 4 + 6
        $this->assertEquals(2, $result['unique_items']); // 2 line items
    }

    /** @test */
    public function it_handles_single_line_item_order(): void
    {
        // Arrange
        $lineItem = new OrderLineItemDTO(
            title: 'Single Product',
            quantity: 1,
            discountedTotalPrice: ['amount' => '50.00', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $orderDTO = new OrderDTO(
            id: 'gid://shopify/Order/single',
            name: '#1010',
            orderNumber: 1010,
            processedAt: '2025-01-20T19:00:00Z',
            financialStatus: 'PAID',
            fulfillmentStatus: 'FULFILLED',
            totalPrice: ['amount' => '50.00', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '50.00', 'currency' => 'GBP'],
            totalTax: ['amount' => '0.00', 'currency' => 'GBP'],
            lineItems: [$lineItem],
            shippingAddress: null,
        );

        // Act
        $resource = new OrderResource($orderDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertEquals(1, $result['total_items']);
        $this->assertEquals(1, $result['unique_items']);
    }

    /** @test */
    public function it_preserves_shipping_address_structure(): void
    {
        // Arrange
        $lineItem = new OrderLineItemDTO(
            title: 'Product',
            quantity: 1,
            discountedTotalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $orderDTO = new OrderDTO(
            id: 'gid://shopify/Order/address',
            name: '#1011',
            orderNumber: 1011,
            processedAt: '2025-01-20T20:00:00Z',
            financialStatus: 'PAID',
            fulfillmentStatus: 'FULFILLED',
            totalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            totalTax: ['amount' => '0.00', 'currency' => 'GBP'],
            lineItems: [$lineItem],
            shippingAddress: [
                'firstName' => 'John',
                'lastName' => 'Doe',
                'address1' => '123 Main St',
                'address2' => 'Apt 4B',
                'city' => 'London',
                'province' => 'England',
                'country' => 'United Kingdom',
                'zip' => 'SW1A 1AA',
                'phone' => '+44123456789',
            ],
        );

        // Act
        $resource = new OrderResource($orderDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertIsArray($result['shipping_address']);
        $this->assertEquals('John', $result['shipping_address']['firstName']);
        $this->assertEquals('Doe', $result['shipping_address']['lastName']);
        $this->assertEquals('123 Main St', $result['shipping_address']['address1']);
        $this->assertEquals('Apt 4B', $result['shipping_address']['address2']);
        $this->assertEquals('London', $result['shipping_address']['city']);
        $this->assertEquals('England', $result['shipping_address']['province']);
        $this->assertEquals('United Kingdom', $result['shipping_address']['country']);
        $this->assertEquals('SW1A 1AA', $result['shipping_address']['zip']);
        $this->assertEquals('+44123456789', $result['shipping_address']['phone']);
    }

    /** @test */
    public function it_handles_different_financial_statuses(): void
    {
        // Arrange
        $lineItem = new OrderLineItemDTO(
            title: 'Product',
            quantity: 1,
            discountedTotalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $statuses = ['PENDING', 'AUTHORIZED', 'PAID', 'PARTIALLY_PAID', 'REFUNDED', 'VOIDED'];

        foreach ($statuses as $index => $status) {
            $orderDTO = new OrderDTO(
                id: "gid://shopify/Order/status{$index}",
                name: "#100{$index}",
                orderNumber: 1000 + $index,
                processedAt: '2025-01-20T10:00:00Z',
                financialStatus: $status,
                fulfillmentStatus: 'UNFULFILLED',
                totalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
                subtotalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
                totalTax: ['amount' => '0.00', 'currency' => 'GBP'],
                lineItems: [$lineItem],
                shippingAddress: null,
            );

            // Act
            $resource = new OrderResource($orderDTO);
            $result = $resource->toArray($this->request);

            // Assert
            $this->assertEquals($status, $result['financial_status']);
        }
    }

    /** @test */
    public function it_handles_different_fulfillment_statuses(): void
    {
        // Arrange
        $lineItem = new OrderLineItemDTO(
            title: 'Product',
            quantity: 1,
            discountedTotalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $statuses = ['UNFULFILLED', 'PARTIALLY_FULFILLED', 'FULFILLED', 'RESTOCKED'];

        foreach ($statuses as $index => $status) {
            $orderDTO = new OrderDTO(
                id: "gid://shopify/Order/fulfillment{$index}",
                name: "#200{$index}",
                orderNumber: 2000 + $index,
                processedAt: '2025-01-20T10:00:00Z',
                financialStatus: 'PAID',
                fulfillmentStatus: $status,
                totalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
                subtotalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
                totalTax: ['amount' => '0.00', 'currency' => 'GBP'],
                lineItems: [$lineItem],
                shippingAddress: null,
            );

            // Act
            $resource = new OrderResource($orderDTO);
            $result = $resource->toArray($this->request);

            // Assert
            $this->assertEquals($status, $result['fulfillment_status']);
        }
    }

    /** @test */
    public function it_includes_all_required_fields(): void
    {
        // Arrange
        $lineItem = new OrderLineItemDTO(
            title: 'Product',
            quantity: 1,
            discountedTotalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $orderDTO = new OrderDTO(
            id: 'gid://shopify/Order/complete',
            name: '#1012',
            orderNumber: 1012,
            processedAt: '2025-01-20T21:00:00Z',
            financialStatus: 'PAID',
            fulfillmentStatus: 'FULFILLED',
            totalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            subtotalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            totalTax: ['amount' => '0.00', 'currency' => 'GBP'],
            lineItems: [$lineItem],
            shippingAddress: null,
        );

        // Act
        $resource = new OrderResource($orderDTO);
        $result = $resource->toArray($this->request);

        // Assert - verify all expected fields are present
        $expectedFields = [
            'id',
            'name',
            'order_number',
            'processed_at',
            'financial_status',
            'fulfillment_status',
            'total_price',
            'subtotal_price',
            'total_tax',
            'currency',
            'line_items',
            'total_items',
            'unique_items',
            'shipping_address',
        ];

        foreach ($expectedFields as $field) {
            $this->assertArrayHasKey($field, $result, "Missing field: {$field}");
        }
    }
}
