<?php

namespace Tests\Unit\DTOs;

use App\DTOs\Cart\CartDTO;
use App\DTOs\Cart\CartLineItemDTO;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CartDTOTest extends TestCase
{
    /**
     * Test that CartDTO can be instantiated with valid data.
     */
    public function test_can_create_cart_dto_with_valid_data(): void
    {
        $lineItem = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/123',
            variantId: 'gid://shopify/ProductVariant/456',
            productId: 'gid://shopify/Product/789',
            title: 'Test Product',
            quantity: 2,
            price: ['amount' => '29.99', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        $dto = new CartDTO(
            id: 'gid://shopify/Cart/abc123',
            lineItems: [$lineItem],
            checkoutUrl: 'https://example.myshopify.com/cart/c/abc123',
            cost: [
                'subtotal' => '59.98',
                'total' => '59.98',
                'currency' => 'GBP',
            ],
            buyerIdentity: [
                'email' => 'customer@example.com',
                'countryCode' => 'GB',
            ],
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:30:00Z',
        );

        $this->assertEquals('gid://shopify/Cart/abc123', $dto->id);
        $this->assertCount(1, $dto->lineItems);
        $this->assertInstanceOf(CartLineItemDTO::class, $dto->lineItems[0]);
        $this->assertEquals('https://example.myshopify.com/cart/c/abc123', $dto->checkoutUrl);
        $this->assertEquals('59.98', $dto->cost['subtotal']);
        $this->assertEquals('59.98', $dto->cost['total']);
        $this->assertEquals('GBP', $dto->cost['currency']);
        $this->assertIsArray($dto->buyerIdentity);
        $this->assertEquals('customer@example.com', $dto->buyerIdentity['email']);
        $this->assertEquals('2025-01-20T10:00:00Z', $dto->createdAt);
        $this->assertEquals('2025-01-20T10:30:00Z', $dto->updatedAt);
    }

    /**
     * Test that CartDTO throws exception when ID is empty.
     */
    public function test_throws_exception_when_id_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Cart ID is required');

        new CartDTO(
            id: '',
            lineItems: [],
            checkoutUrl: '',
            cost: ['subtotal' => '0.00', 'total' => '0.00', 'currency' => 'GBP'],
            buyerIdentity: null,
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:00:00Z',
        );
    }

    /**
     * Test that CartDTO can be created with empty line items.
     */
    public function test_can_create_cart_with_empty_line_items(): void
    {
        $dto = new CartDTO(
            id: 'gid://shopify/Cart/abc123',
            lineItems: [],
            checkoutUrl: 'https://example.myshopify.com/cart/c/abc123',
            cost: ['subtotal' => '0.00', 'total' => '0.00', 'currency' => 'GBP'],
            buyerIdentity: null,
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:00:00Z',
        );

        $this->assertEmpty($dto->lineItems);
        $this->assertEquals(0, $dto->getTotalItems());
    }

    /**
     * Test that CartDTO can be created with null buyer identity (guest cart).
     */
    public function test_can_create_cart_with_null_buyer_identity(): void
    {
        $dto = new CartDTO(
            id: 'gid://shopify/Cart/abc123',
            lineItems: [],
            checkoutUrl: 'https://example.myshopify.com/cart/c/abc123',
            cost: ['subtotal' => '0.00', 'total' => '0.00', 'currency' => 'GBP'],
            buyerIdentity: null,
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:00:00Z',
        );

        $this->assertNull($dto->buyerIdentity);
    }

    /**
     * Test getTotalItems() returns correct sum of quantities.
     */
    public function test_get_total_items_returns_correct_sum(): void
    {
        $lineItem1 = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/123',
            variantId: 'gid://shopify/ProductVariant/456',
            productId: 'gid://shopify/Product/789',
            title: 'Product 1',
            quantity: 2,
            price: ['amount' => '29.99', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        $lineItem2 = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/124',
            variantId: 'gid://shopify/ProductVariant/457',
            productId: 'gid://shopify/Product/790',
            title: 'Product 2',
            quantity: 3,
            price: ['amount' => '19.99', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        $dto = new CartDTO(
            id: 'gid://shopify/Cart/abc123',
            lineItems: [$lineItem1, $lineItem2],
            checkoutUrl: 'https://example.myshopify.com/cart/c/abc123',
            cost: ['subtotal' => '119.95', 'total' => '119.95', 'currency' => 'GBP'],
            buyerIdentity: null,
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:00:00Z',
        );

        $this->assertEquals(5, $dto->getTotalItems());
    }

    /**
     * Test getTotalItems() returns zero for empty cart.
     */
    public function test_get_total_items_returns_zero_for_empty_cart(): void
    {
        $dto = new CartDTO(
            id: 'gid://shopify/Cart/abc123',
            lineItems: [],
            checkoutUrl: 'https://example.myshopify.com/cart/c/abc123',
            cost: ['subtotal' => '0.00', 'total' => '0.00', 'currency' => 'GBP'],
            buyerIdentity: null,
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:00:00Z',
        );

        $this->assertEquals(0, $dto->getTotalItems());
    }

    /**
     * Test fromShopifyResponse() creates DTO from Shopify data with edges format.
     */
    public function test_from_shopify_response_creates_dto_with_edges_format(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Cart/abc123',
            'checkoutUrl' => 'https://example.myshopify.com/cart/c/abc123',
            'lines' => [
                'edges' => [
                    [
                        'node' => [
                            'id' => 'gid://shopify/CartLine/123',
                            'quantity' => 2,
                            'merchandise' => [
                                'id' => 'gid://shopify/ProductVariant/456',
                                'title' => 'Default',
                                'price' => [
                                    'amount' => '29.99',
                                    'currencyCode' => 'GBP',
                                ],
                                'product' => [
                                    'id' => 'gid://shopify/Product/789',
                                    'title' => 'Test Product',
                                ],
                            ],
                            'attributes' => [],
                        ],
                    ],
                ],
            ],
            'cost' => [
                'subtotalAmount' => [
                    'amount' => '59.98',
                    'currencyCode' => 'GBP',
                ],
                'totalAmount' => [
                    'amount' => '59.98',
                    'currencyCode' => 'GBP',
                ],
            ],
            'buyerIdentity' => [
                'email' => 'customer@example.com',
            ],
            'createdAt' => '2025-01-20T10:00:00Z',
            'updatedAt' => '2025-01-20T10:30:00Z',
        ];

        $dto = CartDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/Cart/abc123', $dto->id);
        $this->assertCount(1, $dto->lineItems);
        $this->assertInstanceOf(CartLineItemDTO::class, $dto->lineItems[0]);
        $this->assertEquals('https://example.myshopify.com/cart/c/abc123', $dto->checkoutUrl);
        $this->assertEquals('59.98', $dto->cost['subtotal']);
        $this->assertEquals('59.98', $dto->cost['total']);
        $this->assertEquals('GBP', $dto->cost['currency']);
        $this->assertIsArray($dto->buyerIdentity);
        $this->assertEquals('customer@example.com', $dto->buyerIdentity['email']);
        $this->assertEquals(2, $dto->getTotalItems());
    }

    /**
     * Test fromShopifyResponse() creates DTO from Shopify data without edges format.
     */
    public function test_from_shopify_response_creates_dto_without_edges_format(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Cart/abc123',
            'checkoutUrl' => 'https://example.myshopify.com/cart/c/abc123',
            'lines' => [
                [
                    'id' => 'gid://shopify/CartLine/123',
                    'quantity' => 1,
                    'merchandise' => [
                        'id' => 'gid://shopify/ProductVariant/456',
                        'title' => 'Default',
                        'price' => [
                            'amount' => '29.99',
                            'currencyCode' => 'GBP',
                        ],
                        'product' => [
                            'id' => 'gid://shopify/Product/789',
                            'title' => 'Test Product',
                        ],
                    ],
                    'attributes' => [],
                ],
            ],
            'cost' => [
                'subtotalAmount' => [
                    'amount' => '29.99',
                    'currencyCode' => 'GBP',
                ],
                'totalAmount' => [
                    'amount' => '29.99',
                    'currencyCode' => 'GBP',
                ],
            ],
            'createdAt' => '2025-01-20T10:00:00Z',
            'updatedAt' => '2025-01-20T10:00:00Z',
        ];

        $dto = CartDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/Cart/abc123', $dto->id);
        $this->assertCount(1, $dto->lineItems);
        $this->assertEquals(1, $dto->getTotalItems());
    }

    /**
     * Test fromShopifyResponse() handles missing optional fields.
     */
    public function test_from_shopify_response_handles_missing_optional_fields(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Cart/abc123',
        ];

        $dto = CartDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/Cart/abc123', $dto->id);
        $this->assertEmpty($dto->lineItems);
        $this->assertEquals('', $dto->checkoutUrl);
        $this->assertEquals('0.00', $dto->cost['subtotal']);
        $this->assertEquals('0.00', $dto->cost['total']);
        $this->assertEquals('GBP', $dto->cost['currency']);
        $this->assertNull($dto->buyerIdentity);
    }

    /**
     * Test fromShopifyResponse() handles empty lines array.
     */
    public function test_from_shopify_response_handles_empty_lines(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Cart/abc123',
            'lines' => [],
            'cost' => [
                'subtotalAmount' => [
                    'amount' => '0.00',
                    'currencyCode' => 'GBP',
                ],
                'totalAmount' => [
                    'amount' => '0.00',
                    'currencyCode' => 'GBP',
                ],
            ],
            'createdAt' => '2025-01-20T10:00:00Z',
            'updatedAt' => '2025-01-20T10:00:00Z',
        ];

        $dto = CartDTO::fromShopifyResponse($shopifyData);

        $this->assertEmpty($dto->lineItems);
        $this->assertEquals(0, $dto->getTotalItems());
    }

    /**
     * Test toArray() converts DTO to array including nested line items.
     */
    public function test_to_array_converts_dto_to_array_with_nested_line_items(): void
    {
        $lineItem = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/123',
            variantId: 'gid://shopify/ProductVariant/456',
            productId: 'gid://shopify/Product/789',
            title: 'Test Product',
            quantity: 2,
            price: ['amount' => '29.99', 'currency' => 'GBP'],
            image: 'https://example.com/image.jpg',
            attributes: [],
        );

        $dto = new CartDTO(
            id: 'gid://shopify/Cart/abc123',
            lineItems: [$lineItem],
            checkoutUrl: 'https://example.myshopify.com/cart/c/abc123',
            cost: [
                'subtotal' => '59.98',
                'total' => '59.98',
                'currency' => 'GBP',
            ],
            buyerIdentity: [
                'email' => 'customer@example.com',
            ],
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:30:00Z',
        );

        $array = $dto->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('gid://shopify/Cart/abc123', $array['id']);
        $this->assertIsArray($array['lineItems']);
        $this->assertCount(1, $array['lineItems']);
        $this->assertIsArray($array['lineItems'][0]);
        $this->assertEquals('gid://shopify/CartLine/123', $array['lineItems'][0]['id']);
        $this->assertEquals(2, $array['lineItems'][0]['quantity']);
        $this->assertIsArray($array['cost']);
        $this->assertEquals('59.98', $array['cost']['subtotal']);
        $this->assertIsArray($array['buyerIdentity']);
        $this->assertEquals('customer@example.com', $array['buyerIdentity']['email']);
    }

    /**
     * Test toArray() handles null buyer identity.
     */
    public function test_to_array_handles_null_buyer_identity(): void
    {
        $dto = new CartDTO(
            id: 'gid://shopify/Cart/abc123',
            lineItems: [],
            checkoutUrl: 'https://example.myshopify.com/cart/c/abc123',
            cost: ['subtotal' => '0.00', 'total' => '0.00', 'currency' => 'GBP'],
            buyerIdentity: null,
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:00:00Z',
        );

        $array = $dto->toArray();

        $this->assertNull($array['buyerIdentity']);
    }

    /**
     * Test toArray() handles empty line items.
     */
    public function test_to_array_handles_empty_line_items(): void
    {
        $dto = new CartDTO(
            id: 'gid://shopify/Cart/abc123',
            lineItems: [],
            checkoutUrl: 'https://example.myshopify.com/cart/c/abc123',
            cost: ['subtotal' => '0.00', 'total' => '0.00', 'currency' => 'GBP'],
            buyerIdentity: null,
            createdAt: '2025-01-20T10:00:00Z',
            updatedAt: '2025-01-20T10:00:00Z',
        );

        $array = $dto->toArray();

        $this->assertIsArray($array['lineItems']);
        $this->assertEmpty($array['lineItems']);
    }
}
