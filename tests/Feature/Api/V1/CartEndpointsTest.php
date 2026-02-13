<?php

namespace Tests\Feature\Api\V1;

use Tests\TestCase;
use Tests\Mocks\MockShopifyClient;
use Tests\Helpers\ShopifyResponseFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Integration tests for Cart API endpoints
 * 
 * Tests Requirements: 2.1, 2.2, 2.3, 2.5, 2.6
 */
class CartEndpointsTest extends TestCase
{
    private MockShopifyClient $mockClient;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->mockClient = new MockShopifyClient();
        $this->app->instance('App\Contracts\Shopify\StorefrontApiClientInterface', $this->mockClient);
    }

    public function test_create_cart_returns_standardized_response(): void
    {
        // Arrange
        $cart = ShopifyResponseFactory::cart();
        $this->mockClient->mockResponse(
            'storefront/cart/create_cart.graphql',
            [
                'data' => [
                    'cartCreate' => [
                        'cart' => $cart
                    ]
                ]
            ]
        );

        // Act
        $response = $this->postJson('/api/v1/cart');

        // Assert - Requirement 2.1: Maintain identical API response structures
        $response->assertStatus(201);
        $response->assertJsonStructure([
            'success',
            'message',
            'data' => [
                'cart' => [
                    'id',
                    'checkout_url',
                    'line_items',
                    'subtotal',
                    'total',
                    'currency',
                    'total_items',
                    'unique_items',
                ]
            ],
            'meta'
        ]);

        $response->assertJson([
            'success' => true,
        ]);
    }

    public function test_get_cart_by_id_returns_cart_details(): void
    {
        // Arrange
        $cartId = 'gid://shopify/Cart/test-cart-123';
        $cart = ShopifyResponseFactory::cart(['id' => $cartId]);
        
        $this->mockClient->mockResponse(
            'storefront/cart/get_cart.graphql',
            ShopifyResponseFactory::successResponse('cart', $cart)
        );

        // Act
        $response = $this->getJson('/api/v1/cart/' . urlencode($cartId));

        // Assert - Requirement 2.2: Maintain identical API endpoint paths
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'cart' => [
                    'id' => $cartId,
                ]
            ]
        ]);
    }

    public function test_add_item_to_cart_updates_cart(): void
    {
        // Arrange
        $cartId = 'gid://shopify/Cart/test-cart-123';
        $lineItem = ShopifyResponseFactory::cartLineItem([
            'quantity' => 2,
            'merchandise' => [
                'id' => 'gid://shopify/ProductVariant/123',
                'title' => 'Test Product - Medium',
            ]
        ]);
        
        $cart = ShopifyResponseFactory::cart([
            'id' => $cartId,
            'lineItems' => [$lineItem]
        ]);

        $this->mockClient->mockResponse(
            'storefront/cart/add_line_item.graphql',
            [
                'data' => [
                    'cartLinesAdd' => [
                        'cart' => $cart
                    ]
                ]
            ]
        );

        // Act
        $response = $this->postJson('/api/v1/cart/' . urlencode($cartId) . '/items', [
            'variant_id' => 'gid://shopify/ProductVariant/123',
            'quantity' => 2,
        ]);

        // Assert
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'cart' => [
                    'id' => $cartId,
                ]
            ]
        ]);

        $this->assertIsArray($response->json('data.cart.line_items'));
    }

    public function test_update_cart_item_quantity(): void
    {
        // Arrange
        $cartId = 'gid://shopify/Cart/test-cart-123';
        $lineId = 'gid://shopify/CartLine/456';
        
        $cart = ShopifyResponseFactory::cart(['id' => $cartId]);

        $this->mockClient->mockResponse(
            'storefront/cart/update_line_item.graphql',
            [
                'data' => [
                    'cartLinesUpdate' => [
                        'cart' => $cart
                    ]
                ]
            ]
        );

        // Act
        $response = $this->patchJson('/api/v1/cart/' . urlencode($cartId) . '/items/' . urlencode($lineId), [
            'quantity' => 3,
        ]);

        // Assert
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);
    }

    public function test_remove_item_from_cart(): void
    {
        // Arrange
        $cartId = 'gid://shopify/Cart/test-cart-123';
        $lineId = 'gid://shopify/CartLine/456';
        
        $cart = ShopifyResponseFactory::cart(['id' => $cartId, 'lineItems' => []]);

        $this->mockClient->mockResponse(
            'storefront/cart/remove_line_item.graphql',
            [
                'data' => [
                    'cartLinesRemove' => [
                        'cart' => $cart
                    ]
                ]
            ]
        );

        // Act
        $response = $this->deleteJson('/api/v1/cart/' . urlencode($cartId) . '/items/' . urlencode($lineId));

        // Assert
        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);
    }

    public function test_add_item_validates_request_parameters(): void
    {
        // Arrange
        $cartId = 'gid://shopify/Cart/test-cart-123';

        // Act - Missing required fields
        $response = $this->postJson('/api/v1/cart/' . urlencode($cartId) . '/items', []);

        // Assert - Requirement 2.3: Maintain identical request validation rules
        $response->assertStatus(422);
        $response->assertJson([
            'success' => false,
        ]);
    }

    public function test_cart_response_includes_calculated_fields(): void
    {
        // Arrange
        $lineItems = [
            ShopifyResponseFactory::cartLineItem(['quantity' => 2]),
            ShopifyResponseFactory::cartLineItem(['quantity' => 3]),
        ];
        
        $cart = ShopifyResponseFactory::cart(['lineItems' => $lineItems]);
        
        $this->mockClient->mockResponse(
            'storefront/cart/create_cart.graphql',
            [
                'data' => [
                    'cartCreate' => [
                        'cart' => $cart
                    ]
                ]
            ]
        );

        // Act
        $response = $this->postJson('/api/v1/cart');

        // Assert - Verify calculated fields
        $response->assertStatus(201);
        $this->assertIsInt($response->json('data.cart.total_items'));
        $this->assertIsInt($response->json('data.cart.unique_items'));
        $this->assertEquals(2, $response->json('data.cart.unique_items'));
    }

    public function test_cart_not_found_returns_404(): void
    {
        // Arrange
        $cartId = 'gid://shopify/Cart/nonexistent';
        
        $this->mockClient->mockResponse(
            'storefront/cart/get_cart.graphql',
            ShopifyResponseFactory::successResponse('cart', null)
        );

        // Act
        $response = $this->getJson('/api/v1/cart/' . urlencode($cartId));

        // Assert - Requirement 2.5: Minimize changes to Mobile_App integration contracts
        $response->assertStatus(404);
        $response->assertJson([
            'success' => false,
        ]);
    }
}
