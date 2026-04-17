<?php

namespace Tests\Unit\Resources;

use App\DTOs\Cart\CartLineItemDTO;
use App\Http\Resources\Cart\CartLineItemResource;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * CartLineItemResource Unit Tests
 * 
 * Tests transformation logic from CartLineItemDTO to API response format.
 * Validates field mapping, pricing information, and edge cases.
 */
class CartLineItemResourceTest extends TestCase
{
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = Request::create('/test', 'GET');
    }

    /** @test */
    public function it_transforms_cart_line_item_dto_to_array(): void
    {
        // Arrange
        $lineItemDTO = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/123',
            variantId: 'gid://shopify/ProductVariant/456',
            productId: 'gid://shopify/Product/789',
            title: 'Test Product - Small / Red',
            quantity: 2,
            price: [
                'amount' => '29.99',
                'currency' => 'GBP',
            ],
            image: 'https://example.com/product.jpg',
            attributes: [
                ['key' => 'gift_wrap', 'value' => 'true'],
                ['key' => 'message', 'value' => 'Happy Birthday!'],
            ],
        );

        // Act
        $resource = new CartLineItemResource($lineItemDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('gid://shopify/CartLine/123', $result['id']);
        $this->assertEquals('gid://shopify/ProductVariant/456', $result['variant_id']);
        $this->assertEquals('gid://shopify/Product/789', $result['product_id']);
        $this->assertEquals('Test Product - Small / Red', $result['title']);
        $this->assertEquals(2, $result['quantity']);
        $this->assertEquals('29.99', $result['price']);
        $this->assertEquals('GBP', $result['currency']);
        $this->assertEquals('https://example.com/product.jpg', $result['image']);
        $this->assertCount(2, $result['attributes']);
    }

    /** @test */
    public function it_handles_line_item_with_null_image(): void
    {
        // Arrange
        $lineItemDTO = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/111',
            variantId: 'gid://shopify/ProductVariant/222',
            productId: 'gid://shopify/Product/333',
            title: 'Product Without Image',
            quantity: 1,
            price: [
                'amount' => '15.00',
                'currency' => 'USD',
            ],
            image: null,
            attributes: [],
        );

        // Act
        $resource = new CartLineItemResource($lineItemDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertNull($result['image']);
        $this->assertEmpty($result['attributes']);
    }

    /** @test */
    public function it_handles_different_currency_codes(): void
    {
        // Arrange - GBP
        $lineItemGBP = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/1',
            variantId: 'gid://shopify/ProductVariant/1',
            productId: 'gid://shopify/Product/1',
            title: 'Product GBP',
            quantity: 1,
            price: ['amount' => '29.99', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        // Arrange - USD
        $lineItemUSD = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/2',
            variantId: 'gid://shopify/ProductVariant/2',
            productId: 'gid://shopify/Product/2',
            title: 'Product USD',
            quantity: 1,
            price: ['amount' => '39.99', 'currency' => 'USD'],
            image: null,
            attributes: [],
        );

        // Arrange - EUR
        $lineItemEUR = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/3',
            variantId: 'gid://shopify/ProductVariant/3',
            productId: 'gid://shopify/Product/3',
            title: 'Product EUR',
            quantity: 1,
            price: ['amount' => '34.99', 'currency' => 'EUR'],
            image: null,
            attributes: [],
        );

        // Act
        $resourceGBP = new CartLineItemResource($lineItemGBP);
        $resourceUSD = new CartLineItemResource($lineItemUSD);
        $resourceEUR = new CartLineItemResource($lineItemEUR);

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
        $lineItemSingle = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/444',
            variantId: 'gid://shopify/ProductVariant/444',
            productId: 'gid://shopify/Product/444',
            title: 'Single Item',
            quantity: 1,
            price: ['amount' => '10.00', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        // Arrange - multiple items
        $lineItemMultiple = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/555',
            variantId: 'gid://shopify/ProductVariant/555',
            productId: 'gid://shopify/Product/555',
            title: 'Multiple Items',
            quantity: 5,
            price: ['amount' => '10.00', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        // Act
        $resourceSingle = new CartLineItemResource($lineItemSingle);
        $resourceMultiple = new CartLineItemResource($lineItemMultiple);

        $resultSingle = $resourceSingle->toArray($this->request);
        $resultMultiple = $resourceMultiple->toArray($this->request);

        // Assert
        $this->assertEquals(1, $resultSingle['quantity']);
        $this->assertEquals(5, $resultMultiple['quantity']);
    }

    /** @test */
    public function it_preserves_attributes_structure(): void
    {
        // Arrange
        $lineItemDTO = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/666',
            variantId: 'gid://shopify/ProductVariant/666',
            productId: 'gid://shopify/Product/666',
            title: 'Product with Attributes',
            quantity: 1,
            price: ['amount' => '25.00', 'currency' => 'GBP'],
            image: null,
            attributes: [
                ['key' => 'engraving', 'value' => 'John Doe'],
                ['key' => 'gift_wrap', 'value' => 'true'],
                ['key' => 'color_preference', 'value' => 'blue'],
            ],
        );

        // Act
        $resource = new CartLineItemResource($lineItemDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertCount(3, $result['attributes']);
        $this->assertEquals('engraving', $result['attributes'][0]['key']);
        $this->assertEquals('John Doe', $result['attributes'][0]['value']);
        $this->assertEquals('gift_wrap', $result['attributes'][1]['key']);
        $this->assertEquals('true', $result['attributes'][1]['value']);
        $this->assertEquals('color_preference', $result['attributes'][2]['key']);
        $this->assertEquals('blue', $result['attributes'][2]['value']);
    }

    /** @test */
    public function it_handles_decimal_prices(): void
    {
        // Arrange
        $lineItemDTO = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/777',
            variantId: 'gid://shopify/ProductVariant/777',
            productId: 'gid://shopify/Product/777',
            title: 'Decimal Price Product',
            quantity: 3,
            price: ['amount' => '12.50', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        // Act
        $resource = new CartLineItemResource($lineItemDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertEquals('12.50', $result['price']);
    }

    /** @test */
    public function it_uses_snake_case_for_field_names(): void
    {
        // Arrange
        $lineItemDTO = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/888',
            variantId: 'gid://shopify/ProductVariant/888',
            productId: 'gid://shopify/Product/888',
            title: 'Test Product',
            quantity: 1,
            price: ['amount' => '20.00', 'currency' => 'GBP'],
            image: 'https://example.com/image.jpg',
            attributes: [],
        );

        // Act
        $resource = new CartLineItemResource($lineItemDTO);
        $result = $resource->toArray($this->request);

        // Assert - verify snake_case field names
        $this->assertArrayHasKey('variant_id', $result);
        $this->assertArrayHasKey('product_id', $result);
        
        // Verify camelCase fields are NOT present
        $this->assertArrayNotHasKey('variantId', $result);
        $this->assertArrayNotHasKey('productId', $result);
    }

    /** @test */
    public function it_separates_price_amount_and_currency(): void
    {
        // Arrange
        $lineItemDTO = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/999',
            variantId: 'gid://shopify/ProductVariant/999',
            productId: 'gid://shopify/Product/999',
            title: 'Price Test Product',
            quantity: 1,
            price: ['amount' => '45.99', 'currency' => 'USD'],
            image: null,
            attributes: [],
        );

        // Act
        $resource = new CartLineItemResource($lineItemDTO);
        $result = $resource->toArray($this->request);

        // Assert - price and currency are separate fields
        $this->assertArrayHasKey('price', $result);
        $this->assertArrayHasKey('currency', $result);
        $this->assertEquals('45.99', $result['price']);
        $this->assertEquals('USD', $result['currency']);
        
        // Verify the price field is not an array
        $this->assertIsString($result['price']);
        $this->assertIsString($result['currency']);
    }

    /** @test */
    public function it_handles_empty_attributes_array(): void
    {
        // Arrange
        $lineItemDTO = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/1010',
            variantId: 'gid://shopify/ProductVariant/1010',
            productId: 'gid://shopify/Product/1010',
            title: 'No Attributes Product',
            quantity: 2,
            price: ['amount' => '18.00', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        // Act
        $resource = new CartLineItemResource($lineItemDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertIsArray($result['attributes']);
        $this->assertEmpty($result['attributes']);
    }
}
