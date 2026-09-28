<?php

namespace Tests\Unit\Services\Shopify;

use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\Services\Shopify\CartService;
use Mockery;
use Tests\Helpers\ShopifyResponseFactory;
use Tests\TestCase;

class CartServiceTest extends TestCase
{
    public function test_associate_customer_sends_access_token_and_email_together(): void
    {
        $cartId = 'gid://shopify/Cart/test-cart-123';
        $accessToken = 'test-access-token';
        $email = 'customer@example.com';

        $cart = ShopifyResponseFactory::cart(['id' => $cartId]);

        $mockClient = Mockery::mock(StorefrontApiClientInterface::class);
        $mockClient->shouldReceive('queryWithCurrency')
            ->once()
            ->with(
                'storefront/cart/associate_customer',
                Mockery::on(function (array $variables) use ($cartId, $accessToken, $email) {
                    return $variables['cartId'] === $cartId
                        && $variables['buyerIdentity'] === [
                            'customerAccessToken' => $accessToken,
                            'email' => $email,
                        ];
                })
            )
            ->andReturn([
                'data' => [
                    'cartBuyerIdentityUpdate' => [
                        'cart' => $cart,
                        'userErrors' => [],
                    ],
                ],
            ]);

        $service = new CartService($mockClient);

        $result = $service->associateCustomer($cartId, $accessToken, $email);

        $this->assertEquals($cartId, $result->id);
    }

    public function test_associate_customer_without_email_only_sends_access_token(): void
    {
        $cartId = 'gid://shopify/Cart/test-cart-123';
        $accessToken = 'test-access-token';

        $cart = ShopifyResponseFactory::cart(['id' => $cartId]);

        $mockClient = Mockery::mock(StorefrontApiClientInterface::class);
        $mockClient->shouldReceive('queryWithCurrency')
            ->once()
            ->with(
                'storefront/cart/associate_customer',
                Mockery::on(function (array $variables) use ($accessToken) {
                    return $variables['buyerIdentity'] === [
                        'customerAccessToken' => $accessToken,
                    ];
                })
            )
            ->andReturn([
                'data' => [
                    'cartBuyerIdentityUpdate' => [
                        'cart' => $cart,
                        'userErrors' => [],
                    ],
                ],
            ]);

        $service = new CartService($mockClient);

        $result = $service->associateCustomer($cartId, $accessToken);

        $this->assertEquals($cartId, $result->id);
    }
}
