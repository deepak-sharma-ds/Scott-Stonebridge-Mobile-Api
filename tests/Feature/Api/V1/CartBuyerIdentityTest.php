<?php

namespace Tests\Feature\Api\V1;

use Tests\Helpers\ShopifyResponseFactory;
use Tests\Mocks\MockShopifyClient;
use Tests\TestCase;

/**
 * Integration tests for the cart buyer identity association endpoint.
 *
 * Covers PUT /api/v1/cart/buyer (cart_id passed in the request body,
 * authenticated via the shopify.auth middleware).
 */
class CartBuyerIdentityTest extends TestCase
{
    private MockShopifyClient $mockClient;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = new MockShopifyClient;
        $this->app->instance('App\Contracts\Shopify\StorefrontApiClientInterface', $this->mockClient);
    }

    public function test_it_associates_the_authenticated_customer_access_token_with_the_cart(): void
    {
        $cartId = 'gid://shopify/Cart/test-cart-123';
        $accessToken = 'test-access-token';
        $email = 'customer@example.com';

        $customer = ShopifyResponseFactory::customer(['email' => $email]);
        $this->mockClient->mockResponse(
            'storefront/customer/get_customer_profile',
            ShopifyResponseFactory::successResponse('customer', $customer)
        );

        $cart = ShopifyResponseFactory::cart([
            'id' => $cartId,
            'buyerIdentity' => [
                'email' => $email,
                'phone' => null,
                'customer' => [
                    'id' => $customer['id'],
                    'email' => $email,
                ],
            ],
        ]);

        $this->mockClient->mockResponse(
            'storefront/cart/associate_customer',
            [
                'data' => [
                    'cartBuyerIdentityUpdate' => [
                        'cart' => $cart,
                        'userErrors' => [],
                    ],
                ],
            ]
        );

        $response = $this->putJson('/api/v1/cart/buyer', [
            'cart_id' => $cartId,
            'email' => $email,
        ], [
            'Authorization' => 'Bearer '.$accessToken,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'cart' => [
                    'id' => $cartId,
                ],
            ],
        ]);
    }

    public function test_it_requires_authentication(): void
    {
        $response = $this->putJson('/api/v1/cart/buyer', [
            'cart_id' => 'gid://shopify/Cart/test-cart-123',
            'email' => 'customer@example.com',
        ]);

        $response->assertStatus(401);
    }

    public function test_it_validates_cart_id_and_email_in_the_request_body(): void
    {
        $accessToken = 'test-access-token';

        $customer = ShopifyResponseFactory::customer();
        $this->mockClient->mockResponse(
            'storefront/customer/get_customer_profile',
            ShopifyResponseFactory::successResponse('customer', $customer)
        );

        $response = $this->putJson('/api/v1/cart/buyer', [], [
            'Authorization' => 'Bearer '.$accessToken,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('meta.errors.cart_id.0', 'The cart_id field is required.');
        $response->assertJsonPath('meta.errors.email.0', 'The buyer email address is required.');
    }
}
