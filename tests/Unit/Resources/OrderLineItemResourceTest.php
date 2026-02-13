<?php

namespace Tests\Unit\Resources;

use App\DTOs\Order\OrderLineItemDTO;
use App\Http\Resources\Order\OrderLineItemResource;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * OrderLineItemResource Unit Tests
 * 
 * Tests transformation logic from OrderLineItemDTO to API response format.
 * Validates field mapping, pricing information, and edge cases.
 */
class OrderLineItemResourceTest extends TestCase
{
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = Request::create('/test', 'GET');
    }

    /** @test */
    public function it_transforms_order_line_item_dto_to_array(): void
    {
        // Arrange
        $lineItemDTO = new OrderLineItemDTO(
            title: 'Test Product - Small / Red',
            quantity: 2,
            discountedTotalPrice: [
                'amount' => '59.98',
                'currency' => 'GBP',
            ],
            variantId: 'gid://shopify/ProductVariant/456',
            variantTitle: 'Small / Red',
            image: 'https://example.com/product.jpg',
            productTitle: 'Test Product',
            productHandle: 'test-product',
        );

        // Act
        $resource = new OrderLineItemResource($lineItemDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('Test Product - Small / Red', $result['title']);
        $this->assertEquals(2, $result['quantity']);
        $this->assertEquals('59.98', $result['price']);
        $this->assertEquals('GBP', $result['currency']);
        $this->assertEquals('gid://shopify/ProductVariant/456', $result['variant_id']);
        $this->assertEquals('Small / Red', $result['variant_title']);
        $this->assertEquals('https://example.com/product.jpg', $result['image']);
        $this->assertEquals('Test Product', $result['product_title']);
        $this->assertEquals('test-product', $result['product_handle']);
    }

    /** @test */
    public function it_handles_line_item_with_null_optional_fields(): void
    {
        // Arrange
        $lineItemDTO = new OrderLineItemDTO(
            title: 'Product Without Variant',
            quantity: 1,
            discountedTotalPrice: [
                'amount' => '15.00',
                'currency' => 'USD',
            ],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        // Act
        $resource = new OrderLineItemResource($lineItemDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertEquals('Product Without Variant', $result['title']);
        $this->assertEquals(1, $result['quantity']);
        $this->assertEquals('15.00', $result['price']);
        $this->assertEquals('USD', $result['currency']);
        $this->assertNull($result['variant_id']);
        $this->assertNull($result['variant_title']);
        $this->assertNull($result['image']);
        $this->assertNull($result['product_title']);
        $this->assertNull($result['product_handle']);
    }

    /** @test */
    public function it_handles_different_currency_codes(): void
    {
        // Arrange - GBP
        $lineItemGBP = new OrderLineItemDTO(
            title: 'Product GBP',
            quantity: 1,
            discountedTotalPrice: ['amount' => '29.99', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        // Arrange - USD
        $lineItemUSD = new OrderLineItemDTO(
            title: 'Product USD',
            quantity: 1,
            discountedTotalPrice: ['amount' => '39.99', 'currency' => 'USD'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        // Arrange - EUR
        $lineItemEUR = new OrderLineItemDTO(
            title: 'Product EUR',
            quantity: 1,
            discountedTotalPrice: ['amount' => '34.99', 'currency' => 'EUR'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        // Act
        $resourceGBP = new OrderLineItemResource($lineItemGBP);
        $resourceUSD = new OrderLineItemResource($lineItemUSD);
        $resourceEUR = new OrderLineItemResource($lineItemEUR);

        $resultGBP = $resourceGBP->toArray($this->request);
        $resultUSD = $resourceUSD->toArray($this->request);
        $resultEUR = $resourceEUR->toArray($this->request);

        // Assert
        $this->assertEquals('GBP', $resultGBP['currency']);
        $this->assertEquals('USD', $resultUSD['currency']);
        $this->assertEquals('EUR', $resultEUR['currency']);
    }

    /** @test */
    public function it_handles_different_quantities(): void
    {
        // Arrange - single item
        $lineItemSingle = new OrderLineItemDTO(
            title: 'Single Item',
            quantity: 1,
            discountedTotalPrice: ['amount' => '10.00', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        // Arrange - multiple items
        $lineItemMultiple = new OrderLineItemDTO(
            title: 'Multiple Items',
            quantity: 10,
            discountedTotalPrice: ['amount' => '100.00', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        // Act
        $resourceSingle = new OrderLineItemResource($lineItemSingle);
        $resourceMultiple = new OrderLineItemResource($lineItemMultiple);

        $resultSingle = $resourceSingle->toArray($this->request);
        $resultMultiple = $resourceMultiple->toArray($this->request);

        // Assert
        $this->assertEquals(1, $resultSingle['quantity']);
        $this->assertEquals(10, $resultMultiple['quantity']);
    }

    /** @test */
    public function it_handles_decimal_prices(): void
    {
        // Arrange
        $lineItemDTO = new OrderLineItemDTO(
            title: 'Decimal Price Product',
            quantity: 3,
            discountedTotalPrice: ['amount' => '37.50', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        // Act
        $resource = new OrderLineItemResource($lineItemDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertEquals('37.50', $result['price']);
    }

    /** @test */
    public function it_uses_snake_case_for_field_names(): void
    {
        // Arrange
        $lineItemDTO = new OrderLineItemDTO(
            title: 'Test Product',
            quantity: 1,
            discountedTotalPrice: ['amount' => '20.00', 'currency' => 'GBP'],
            variantId: 'gid://shopify/ProductVariant/123',
            variantTitle: 'Default',
            image: 'https://example.com/image.jpg',
            productTitle: 'Test Product',
            productHandle: 'test-product',
        );

        // Act
        $resource = new OrderLineItemResource($lineItemDTO);
        $result = $resource->toArray($this->request);

        // Assert - verify snake_case field names
        $this->assertArrayHasKey('variant_id', $result);
        $this->assertArrayHasKey('variant_title', $result);
        $this->assertArrayHasKey('product_title', $result);
        $this->assertArrayHasKey('product_handle', $result);
        
        // Verify camelCase fields are NOT present
        $this->assertArrayNotHasKey('variantId', $result);
        $this->assertArrayNotHasKey('variantTitle', $result);
        $this->assertArrayNotHasKey('productTitle', $result);
        $this->assertArrayNotHasKey('productHandle', $result);
    }

    /** @test */
    public function it_separates_price_amount_and_currency(): void
    {
        // Arrange
        $lineItemDTO = new OrderLineItemDTO(
            title: 'Price Test Product',
            quantity: 1,
            discountedTotalPrice: ['amount' => '45.99', 'currency' => 'USD'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        // Act
        $resource = new OrderLineItemResource($lineItemDTO);
        $result = $resource->toArray($this->request);

        // Assert - price and currency are separate fields
        $this->assertArrayHasKey('price', $result);
        $this->assertArrayHasKey('currency', $result);
        $this->assertEquals('45.99', $result['price']);
        $this->assertEquals('USD', $result['currency']);
        
        // Verify the price field is not an array
        $this->assertIsString($result['price']);
        $this->assertIsString($result['currency']);
        
        // Verify discountedTotalPrice is not exposed
        $this->assertArrayNotHasKey('discountedTotalPrice', $result);
        $this->assertArrayNotHasKey('discounted_total_price', $result);
    }

    /** @test */
    public function it_handles_product_with_variant_information(): void
    {
        // Arrange
        $lineItemDTO = new OrderLineItemDTO(
            title: 'T-Shirt - Large / Blue',
            quantity: 2,
            discountedTotalPrice: ['amount' => '49.98', 'currency' => 'GBP'],
            variantId: 'gid://shopify/ProductVariant/789',
            variantTitle: 'Large / Blue',
            image: 'https://example.com/tshirt-blue.jpg',
            productTitle: 'T-Shirt',
            productHandle: 't-shirt',
        );

        // Act
        $resource = new OrderLineItemResource($lineItemDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertEquals('T-Shirt - Large / Blue', $result['title']);
        $this->assertEquals('gid://shopify/ProductVariant/789', $result['variant_id']);
        $this->assertEquals('Large / Blue', $result['variant_title']);
        $this->assertEquals('T-Shirt', $result['product_title']);
        $this->assertEquals('t-shirt', $result['product_handle']);
    }

    /** @test */
    public function it_handles_product_without_image(): void
    {
        // Arrange
        $lineItemDTO = new OrderLineItemDTO(
            title: 'Digital Product',
            quantity: 1,
            discountedTotalPrice: ['amount' => '9.99', 'currency' => 'GBP'],
            variantId: 'gid://shopify/ProductVariant/111',
            variantTitle: 'Standard',
            image: null,
            productTitle: 'Digital Product',
            productHandle: 'digital-product',
        );

        // Act
        $resource = new OrderLineItemResource($lineItemDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertNull($result['image']);
    }

    /** @test */
    public function it_includes_all_required_fields(): void
    {
        // Arrange
        $lineItemDTO = new OrderLineItemDTO(
            title: 'Complete Product',
            quantity: 5,
            discountedTotalPrice: ['amount' => '125.00', 'currency' => 'GBP'],
            variantId: 'gid://shopify/ProductVariant/999',
            variantTitle: 'Medium',
            image: 'https://example.com/complete.jpg',
            productTitle: 'Complete Product',
            productHandle: 'complete-product',
        );

        // Act
        $resource = new OrderLineItemResource($lineItemDTO);
        $result = $resource->toArray($this->request);

        // Assert - verify all expected fields are present
        $expectedFields = [
            'title',
            'quantity',
            'price',
            'currency',
            'variant_id',
            'variant_title',
            'image',
            'product_title',
            'product_handle',
        ];

        foreach ($expectedFields as $field) {
            $this->assertArrayHasKey($field, $result, "Missing field: {$field}");
        }
    }
}
