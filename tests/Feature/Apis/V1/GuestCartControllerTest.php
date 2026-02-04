<?php

namespace Tests\Feature\Apis\V1;

use App\Contracts\Shopify\CartServiceInterface;
use App\DTOs\Shopify\CartDTO;
use App\DTOs\Shopify\CartLineItemDTO;
use App\DTOs\Shopify\MoneyDTO;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class GuestCartControllerTest extends TestCase
{
    private $cartService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->cartService = Mockery::mock(CartServiceInterface::class);
        $this->app->instance(CartServiceInterface::class, $this->cartService);
    }

    public function test_create_cart_returns_successful_response()
    {
        // Arrange
        $cartDto = $this->createMockCartDTO('cart-123');

        $this->cartService
            ->shouldReceive('createGuestCart')
            ->once()
            ->with([], 'US')
            ->andReturn($cartDto);

        // Act
        $response = $this->postJson('/api/v1/guest/cart');

        // Assert
        $response->assertStatus(201)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => 'cart-123',
                    'checkout_url' => 'https://checkout.shopify.com/123'
                ]
            ]);
    }

    public function test_add_items_to_cart_returns_successful_response()
    {
        // Arrange
        $cartDto = $this->createMockCartDTO('cart-123');
        $items = [
            [
                'merchandiseId' => 'gid://shopify/ProductVariant/1',
                'quantity' => 1
            ]
        ];

        $this->cartService
            ->shouldReceive('addCartLines')
            ->once()
            ->with('cart-123', $items)
            ->andReturn($cartDto);

        // Act
        $response = $this->postJson('/api/v1/guest/cart/cart-123/items', [
            'line_items' => $items
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'id' => 'cart-123'
                ]
            ]);
    }

    public function test_get_cart_returns_404_when_not_found()
    {
        // Arrange
        $this->cartService
            ->shouldReceive('getCart')
            ->once()
            ->with('unknown-cart')
            ->andReturn(null);

        // Act
        $response = $this->getJson('/api/v1/guest/cart/unknown-cart');

        // Assert
        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Cart not found'
            ]);
    }

    private function createMockCartDTO(string $id): CartDTO
    {
        return new CartDTO(
            id: $id,
            checkoutUrl: "https://checkout.shopify.com/{$id}",
            lines: new Collection([
                new CartLineItemDTO(
                    id: 'line-1',
                    quantity: 1,
                    merchandiseId: 'gid://shopify/ProductVariant/1',
                    totalAmount: new MoneyDTO(10.00, 'USD'),
                    attributes: []
                )
            ]),
            totalAmount: new MoneyDTO(10.00, 'USD'),
            subtotalAmount: new MoneyDTO(10.00, 'USD'),
            totalTaxAmount: new MoneyDTO(0.00, 'USD'),
            discountCode: null,
            discountAmount: null,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable()
        );
    }
}
