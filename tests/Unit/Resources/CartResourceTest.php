<?php

namespace Tests\Unit\Resources;

use App\DTOs\Cart\CartDTO;
use App\DTOs\Cart\CartLineItemDTO;
use App\Http\Resources\Cart\CartResource;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * CartResource Unit Tests
 * 
 * Tests transformation logic from CartDTO to API response format.
 * Validates field mapping, calculated fields, nested resource handling, and edge cases.
 */
class CartResourceTest extends TestCase
{
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = Request::create('/test', 'GET');
    }

    /** @test */
    public function it_transforms_cart_dto_to_array(): void
    {
        // Arrange
        $lineItem1 = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/1',
            variantId: 'gid://shopify/ProductVariant/1',
            productId: 'gid://shopify/Product/1',
            title: 'Product 1',
            quantity: 2,
            price: ['amount' => '10.00', 'currency' => 'GBP'],
            image: 'https://example.com/img1.jpg',
            attributes: [],
        );

        $lineItem2 = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/2',
            variantId: 'gid://shopify/ProductVariant/2',
            productId: 'gid://shopify/Product/2',
            title: 'Product 2',
            quantity: 3,
            price: ['amount' => '15.00', 'currency' => 'GBP'],
            image: 'https://example.com/img2.jpg',
            attributes: [],
        );

        $cartDTO = new CartDTO(
            id: 'gid://shopify/Cart/123',
            lineItems: [$lineItem1, $lineItem2],
            checkoutUrl: 'https://example.myshopify.com/cart/c/123',
            cost: [
                'subtotal' => '65.00',
                'total' => '65.00',
                'currency' => 'GBP',
            ],
            buyerIdentity: [
                'email' => 'customer@example.com',
                'countryCode' => 'GB',
            ],
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:30:00Z',
        );

        // Act
        $resource = new CartResource($cartDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('gid://shopify/Cart/123', $result['id']);
        $this->assertEquals('https://example.myshopify.com/cart/c/123', $result['checkout_url']);
        $this->assertEquals('65.00', $result['subtotal']);
        $this->assertEquals('65.00', $result['total']);
        $this->assertEquals('GBP', $result['currency']);
        $this->assertEquals(5, $result['total_items']); // 2 + 3
        $this->assertEquals(2, $result['unique_items']); // 2 line items
        $this->assertEquals('customer@example.com', $result['buyer_identity']['email']);
        $this->assertEquals('2025-01-20T10:00:00Z', $result['created_at']);
        $this->assertEquals('2025-01-20T10:30:00Z', $result['updated_at']);
    }

    /** @test */
    public function it_transforms_nested_line_items_using_line_item_resource(): void
    {
        // Arrange
        $lineItem = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/456',
            variantId: 'gid://shopify/ProductVariant/789',
            productId: 'gid://shopify/Product/101',
            title: 'Test Product',
            quantity: 1,
            price: ['amount' => '25.00', 'currency' => 'USD'],
            image: null,
            attributes: [],
        );

        $cartDTO = new CartDTO(
            id: 'gid://shopify/Cart/456',
            lineItems: [$lineItem],
            checkoutUrl: 'https://example.myshopify.com/cart/c/456',
            cost: [
                'subtotal' => '25.00',
                'total' => '25.00',
                'currency' => 'USD',
            ],
            buyerIdentity: null,
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:00:00Z',
        );

        // Act
        $resource = new CartResource($cartDTO);
        $result = $resource->toArray($this->request);

        // Assert - line_items is a ResourceCollection, resolve it to array
        $this->assertInstanceOf(\Illuminate\Http\Resources\Json\AnonymousResourceCollection::class, $result['line_items']);
        $lineItemsArray = $result['line_items']->resolve($this->request);
        
        $this->assertIsArray($lineItemsArray);
        $this->assertCount(1, $lineItemsArray);
        
        $lineItemResult = $lineItemsArray[0];
        $this->assertEquals('gid://shopify/CartLine/456', $lineItemResult['id']);
        $this->assertEquals('gid://shopify/ProductVariant/789', $lineItemResult['variant_id']);
        $this->assertEquals('Test Product', $lineItemResult['title']);
        $this->assertEquals(1, $lineItemResult['quantity']);
        $this->assertEquals('25.00', $lineItemResult['price']);
        $this->assertEquals('USD', $lineItemResult['currency']);
    }

    /** @test */
    public function it_calculates_total_items_correctly(): void
    {
        // Arrange
        $lineItem1 = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/1',
            variantId: 'gid://shopify/ProductVariant/1',
            productId: 'gid://shopify/Product/1',
            title: 'Product 1',
            quantity: 5,
            price: ['amount' => '10.00', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        $lineItem2 = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/2',
            variantId: 'gid://shopify/ProductVariant/2',
            productId: 'gid://shopify/Product/2',
            title: 'Product 2',
            quantity: 3,
            price: ['amount' => '15.00', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        $lineItem3 = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/3',
            variantId: 'gid://shopify/ProductVariant/3',
            productId: 'gid://shopify/Product/3',
            title: 'Product 3',
            quantity: 2,
            price: ['amount' => '20.00', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        $cartDTO = new CartDTO(
            id: 'gid://shopify/Cart/789',
            lineItems: [$lineItem1, $lineItem2, $lineItem3],
            checkoutUrl: 'https://example.myshopify.com/cart/c/789',
            cost: [
                'subtotal' => '135.00',
                'total' => '135.00',
                'currency' => 'GBP',
            ],
            buyerIdentity: null,
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:00:00Z',
        );

        // Act
        $resource = new CartResource($cartDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertEquals(10, $result['total_items']); // 5 + 3 + 2
        $this->assertEquals(3, $result['unique_items']); // 3 line items
    }

    /** @test */
    public function it_handles_empty_cart(): void
    {
        // Arrange
        $cartDTO = new CartDTO(
            id: 'gid://shopify/Cart/empty',
            lineItems: [],
            checkoutUrl: 'https://example.myshopify.com/cart/c/empty',
            cost: [
                'subtotal' => '0.00',
                'total' => '0.00',
                'currency' => 'GBP',
            ],
            buyerIdentity: null,
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:00:00Z',
        );

        // Act
        $resource = new CartResource($cartDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertEmpty($result['line_items']);
        $this->assertEquals(0, $result['total_items']);
        $this->assertEquals(0, $result['unique_items']);
        $this->assertEquals('0.00', $result['subtotal']);
        $this->assertEquals('0.00', $result['total']);
    }

    /** @test */
    public function it_handles_cart_with_null_buyer_identity(): void
    {
        // Arrange - guest cart
        $lineItem = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/1',
            variantId: 'gid://shopify/ProductVariant/1',
            productId: 'gid://shopify/Product/1',
            title: 'Product',
            quantity: 1,
            price: ['amount' => '10.00', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        $cartDTO = new CartDTO(
            id: 'gid://shopify/Cart/guest',
            lineItems: [$lineItem],
            checkoutUrl: 'https://example.myshopify.com/cart/c/guest',
            cost: [
                'subtotal' => '10.00',
                'total' => '10.00',
                'currency' => 'GBP',
            ],
            buyerIdentity: null,
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:00:00Z',
        );

        // Act
        $resource = new CartResource($cartDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertNull($result['buyer_identity']);
    }

    /** @test */
    public function it_handles_different_currency_codes(): void
    {
        // Arrange - GBP
        $lineItemGBP = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/1',
            variantId: 'gid://shopify/ProductVariant/1',
            productId: 'gid://shopify/Product/1',
            title: 'Product',
            quantity: 1,
            price: ['amount' => '10.00', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        $cartGBP = new CartDTO(
            id: 'gid://shopify/Cart/gbp',
            lineItems: [$lineItemGBP],
            checkoutUrl: 'https://example.myshopify.com/cart/c/gbp',
            cost: ['subtotal' => '10.00', 'total' => '10.00', 'currency' => 'GBP'],
            buyerIdentity: null,
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:00:00Z',
        );

        // Arrange - USD
        $lineItemUSD = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/2',
            variantId: 'gid://shopify/ProductVariant/2',
            productId: 'gid://shopify/Product/2',
            title: 'Product',
            quantity: 1,
            price: ['amount' => '15.00', 'currency' => 'USD'],
            image: null,
            attributes: [],
        );

        $cartUSD = new CartDTO(
            id: 'gid://shopify/Cart/usd',
            lineItems: [$lineItemUSD],
            checkoutUrl: 'https://example.myshopify.com/cart/c/usd',
            cost: ['subtotal' => '15.00', 'total' => '15.00', 'currency' => 'USD'],
            buyerIdentity: null,
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:00:00Z',
        );

        // Act
        $resourceGBP = new CartResource($cartGBP);
        $resourceUSD = new CartResource($cartUSD);

        $resultGBP = $resourceGBP->toArray($this->request);
        $resultUSD = $resourceUSD->toArray($this->request);

        // Assert
        $this->assertEquals('GBP', $resultGBP['currency']);
        $this->assertEquals('USD', $resultUSD['currency']);
    }

    /** @test */
    public function it_separates_subtotal_total_and_currency(): void
    {
        // Arrange
        $lineItem = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/1',
            variantId: 'gid://shopify/ProductVariant/1',
            productId: 'gid://shopify/Product/1',
            title: 'Product',
            quantity: 1,
            price: ['amount' => '100.00', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        $cartDTO = new CartDTO(
            id: 'gid://shopify/Cart/cost',
            lineItems: [$lineItem],
            checkoutUrl: 'https://example.myshopify.com/cart/c/cost',
            cost: [
                'subtotal' => '100.00',
                'total' => '120.00', // includes tax/shipping
                'currency' => 'GBP',
            ],
            buyerIdentity: null,
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:00:00Z',
        );

        // Act
        $resource = new CartResource($cartDTO);
        $result = $resource->toArray($this->request);

        // Assert - subtotal, total, and currency are separate fields
        $this->assertArrayHasKey('subtotal', $result);
        $this->assertArrayHasKey('total', $result);
        $this->assertArrayHasKey('currency', $result);
        $this->assertEquals('100.00', $result['subtotal']);
        $this->assertEquals('120.00', $result['total']);
        $this->assertEquals('GBP', $result['currency']);
        
        // Verify they are not nested in a cost object
        $this->assertArrayNotHasKey('cost', $result);
    }

    /** @test */
    public function it_uses_snake_case_for_field_names(): void
    {
        // Arrange
        $lineItem = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/1',
            variantId: 'gid://shopify/ProductVariant/1',
            productId: 'gid://shopify/Product/1',
            title: 'Product',
            quantity: 1,
            price: ['amount' => '10.00', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        $cartDTO = new CartDTO(
            id: 'gid://shopify/Cart/snake',
            lineItems: [$lineItem],
            checkoutUrl: 'https://example.myshopify.com/cart/c/snake',
            cost: ['subtotal' => '10.00', 'total' => '10.00', 'currency' => 'GBP'],
            buyerIdentity: ['email' => 'test@example.com'],
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:30:00Z',
        );

        // Act
        $resource = new CartResource($cartDTO);
        $result = $resource->toArray($this->request);

        // Assert - verify snake_case field names
        $this->assertArrayHasKey('checkout_url', $result);
        $this->assertArrayHasKey('line_items', $result);
        $this->assertArrayHasKey('total_items', $result);
        $this->assertArrayHasKey('unique_items', $result);
        $this->assertArrayHasKey('buyer_identity', $result);
        $this->assertArrayHasKey('created_at', $result);
        $this->assertArrayHasKey('updated_at', $result);
        
        // Verify camelCase fields are NOT present
        $this->assertArrayNotHasKey('checkoutUrl', $result);
        $this->assertArrayNotHasKey('lineItems', $result);
        $this->assertArrayNotHasKey('totalItems', $result);
        $this->assertArrayNotHasKey('uniqueItems', $result);
        $this->assertArrayNotHasKey('buyerIdentity', $result);
        $this->assertArrayNotHasKey('createdAt', $result);
        $this->assertArrayNotHasKey('updatedAt', $result);
    }

    /** @test */
    public function it_includes_calculated_fields(): void
    {
        // Arrange
        $lineItem1 = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/1',
            variantId: 'gid://shopify/ProductVariant/1',
            productId: 'gid://shopify/Product/1',
            title: 'Product 1',
            quantity: 4,
            price: ['amount' => '10.00', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        $lineItem2 = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/2',
            variantId: 'gid://shopify/ProductVariant/2',
            productId: 'gid://shopify/Product/2',
            title: 'Product 2',
            quantity: 6,
            price: ['amount' => '15.00', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        $cartDTO = new CartDTO(
            id: 'gid://shopify/Cart/calc',
            lineItems: [$lineItem1, $lineItem2],
            checkoutUrl: 'https://example.myshopify.com/cart/c/calc',
            cost: ['subtotal' => '130.00', 'total' => '130.00', 'currency' => 'GBP'],
            buyerIdentity: null,
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:00:00Z',
        );

        // Act
        $resource = new CartResource($cartDTO);
        $result = $resource->toArray($this->request);

        // Assert - calculated fields are present
        $this->assertArrayHasKey('total_items', $result);
        $this->assertArrayHasKey('unique_items', $result);
        $this->assertEquals(10, $result['total_items']); // 4 + 6
        $this->assertEquals(2, $result['unique_items']); // 2 line items
    }

    /** @test */
    public function it_handles_single_line_item_cart(): void
    {
        // Arrange
        $lineItem = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/1',
            variantId: 'gid://shopify/ProductVariant/1',
            productId: 'gid://shopify/Product/1',
            title: 'Single Product',
            quantity: 1,
            price: ['amount' => '50.00', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        $cartDTO = new CartDTO(
            id: 'gid://shopify/Cart/single',
            lineItems: [$lineItem],
            checkoutUrl: 'https://example.myshopify.com/cart/c/single',
            cost: ['subtotal' => '50.00', 'total' => '50.00', 'currency' => 'GBP'],
            buyerIdentity: null,
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:00:00Z',
        );

        // Act
        $resource = new CartResource($cartDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertEquals(1, $result['total_items']);
        $this->assertEquals(1, $result['unique_items']);
    }

    /** @test */
    public function it_preserves_buyer_identity_structure(): void
    {
        // Arrange
        $lineItem = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/1',
            variantId: 'gid://shopify/ProductVariant/1',
            productId: 'gid://shopify/Product/1',
            title: 'Product',
            quantity: 1,
            price: ['amount' => '10.00', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        $cartDTO = new CartDTO(
            id: 'gid://shopify/Cart/buyer',
            lineItems: [$lineItem],
            checkoutUrl: 'https://example.myshopify.com/cart/c/buyer',
            cost: ['subtotal' => '10.00', 'total' => '10.00', 'currency' => 'GBP'],
            buyerIdentity: [
                'email' => 'customer@example.com',
                'phone' => '+44123456789',
                'countryCode' => 'GB',
            ],
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:00:00Z',
        );

        // Act
        $resource = new CartResource($cartDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertIsArray($result['buyer_identity']);
        $this->assertEquals('customer@example.com', $result['buyer_identity']['email']);
        $this->assertEquals('+44123456789', $result['buyer_identity']['phone']);
        $this->assertEquals('GB', $result['buyer_identity']['countryCode']);
    }
}
