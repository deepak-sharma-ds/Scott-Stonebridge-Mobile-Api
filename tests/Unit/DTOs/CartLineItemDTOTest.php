<?php

namespace Tests\Unit\DTOs;

use App\DTOs\Cart\CartLineItemDTO;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class CartLineItemDTOTest extends TestCase
{
    /**
     * Test that CartLineItemDTO can be instantiated with valid data.
     */
    public function test_can_create_cart_line_item_dto_with_valid_data(): void
    {
        $dto = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/123',
            variantId: 'gid://shopify/ProductVariant/456',
            productId: 'gid://shopify/Product/789',
            title: 'Test Product - Default',
            quantity: 2,
            price: [
                'amount' => '29.99',
                'currency' => 'GBP',
            ],
            image: 'https://example.com/image.jpg',
            attributes: [
                ['key' => 'color', 'value' => 'blue'],
            ],
        );

        $this->assertEquals('gid://shopify/CartLine/123', $dto->id);
        $this->assertEquals('gid://shopify/ProductVariant/456', $dto->variantId);
        $this->assertEquals('gid://shopify/Product/789', $dto->productId);
        $this->assertEquals('Test Product - Default', $dto->title);
        $this->assertEquals(2, $dto->quantity);
        $this->assertEquals('29.99', $dto->price['amount']);
        $this->assertEquals('GBP', $dto->price['currency']);
        $this->assertEquals('https://example.com/image.jpg', $dto->image);
        $this->assertCount(1, $dto->attributes);
    }

    /**
     * Test that CartLineItemDTO throws exception when ID is empty.
     */
    public function test_throws_exception_when_id_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Line item ID is required');

        new CartLineItemDTO(
            id: '',
            variantId: 'gid://shopify/ProductVariant/456',
            productId: 'gid://shopify/Product/789',
            title: 'Test Product',
            quantity: 1,
            price: ['amount' => '29.99', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );
    }

    /**
     * Test that CartLineItemDTO throws exception when variant ID is empty.
     */
    public function test_throws_exception_when_variant_id_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Variant ID is required');

        new CartLineItemDTO(
            id: 'gid://shopify/CartLine/123',
            variantId: '',
            productId: 'gid://shopify/Product/789',
            title: 'Test Product',
            quantity: 1,
            price: ['amount' => '29.99', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );
    }

    /**
     * Test that CartLineItemDTO throws exception when product ID is empty.
     */
    public function test_throws_exception_when_product_id_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Product ID is required');

        new CartLineItemDTO(
            id: 'gid://shopify/CartLine/123',
            variantId: 'gid://shopify/ProductVariant/456',
            productId: '',
            title: 'Test Product',
            quantity: 1,
            price: ['amount' => '29.99', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );
    }

    /**
     * Test that CartLineItemDTO throws exception when title is empty.
     */
    public function test_throws_exception_when_title_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Line item title is required');

        new CartLineItemDTO(
            id: 'gid://shopify/CartLine/123',
            variantId: 'gid://shopify/ProductVariant/456',
            productId: 'gid://shopify/Product/789',
            title: '',
            quantity: 1,
            price: ['amount' => '29.99', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );
    }

    /**
     * Test that CartLineItemDTO throws exception when quantity is zero.
     */
    public function test_throws_exception_when_quantity_is_zero(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be positive');

        new CartLineItemDTO(
            id: 'gid://shopify/CartLine/123',
            variantId: 'gid://shopify/ProductVariant/456',
            productId: 'gid://shopify/Product/789',
            title: 'Test Product',
            quantity: 0,
            price: ['amount' => '29.99', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );
    }

    /**
     * Test that CartLineItemDTO throws exception when quantity is negative.
     */
    public function test_throws_exception_when_quantity_is_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be positive');

        new CartLineItemDTO(
            id: 'gid://shopify/CartLine/123',
            variantId: 'gid://shopify/ProductVariant/456',
            productId: 'gid://shopify/Product/789',
            title: 'Test Product',
            quantity: -1,
            price: ['amount' => '29.99', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );
    }

    /**
     * Test fromShopifyResponse() creates DTO from Shopify data.
     */
    public function test_from_shopify_response_creates_dto(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/CartLine/123',
            'quantity' => 3,
            'merchandise' => [
                'id' => 'gid://shopify/ProductVariant/456',
                'title' => 'Default',
                'price' => [
                    'amount' => '49.99',
                    'currencyCode' => 'USD',
                ],
                'image' => [
                    'url' => 'https://example.com/variant-image.jpg',
                ],
                'product' => [
                    'id' => 'gid://shopify/Product/789',
                    'title' => 'Test Product',
                ],
            ],
            'attributes' => [
                ['key' => 'gift_wrap', 'value' => 'yes'],
            ],
        ];

        $dto = CartLineItemDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/CartLine/123', $dto->id);
        $this->assertEquals('gid://shopify/ProductVariant/456', $dto->variantId);
        $this->assertEquals('gid://shopify/Product/789', $dto->productId);
        $this->assertEquals('Default', $dto->title);
        $this->assertEquals(3, $dto->quantity);
        $this->assertEquals('49.99', $dto->price['amount']);
        $this->assertEquals('USD', $dto->price['currency']);
        $this->assertEquals('https://example.com/variant-image.jpg', $dto->image);
        $this->assertCount(1, $dto->attributes);
    }

    /**
     * Test fromShopifyResponse() handles minimal merchandise data.
     */
    public function test_from_shopify_response_handles_minimal_merchandise_data(): void
    {
        $shopifyData = [
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
                ],
            ],
        ];

        $dto = CartLineItemDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/CartLine/123', $dto->id);
        $this->assertEquals('gid://shopify/ProductVariant/456', $dto->variantId);
        $this->assertEquals('gid://shopify/Product/789', $dto->productId);
        $this->assertEquals('Default', $dto->title);
        $this->assertEquals(1, $dto->quantity);
        $this->assertEquals('29.99', $dto->price['amount']);
        $this->assertEquals('GBP', $dto->price['currency']);
    }

    /**
     * Test fromShopifyResponse() uses product featured image as fallback.
     */
    public function test_from_shopify_response_uses_product_featured_image_fallback(): void
    {
        $shopifyData = [
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
                    'featuredImage' => [
                        'url' => 'https://example.com/product-image.jpg',
                    ],
                ],
            ],
        ];

        $dto = CartLineItemDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('https://example.com/product-image.jpg', $dto->image);
    }

    /**
     * Test fromShopifyResponse() handles missing optional fields.
     */
    public function test_from_shopify_response_handles_missing_optional_fields(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/CartLine/123',
            'merchandise' => [
                'id' => 'gid://shopify/ProductVariant/456',
                'title' => 'Default',
                'price' => [
                    'amount' => '29.99',
                    'currencyCode' => 'GBP',
                ],
                'product' => [
                    'id' => 'gid://shopify/Product/789',
                ],
            ],
        ];

        $dto = CartLineItemDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals(1, $dto->quantity); // Default quantity
        $this->assertNull($dto->image);
        $this->assertEmpty($dto->attributes);
    }

    /**
     * Test toArray() converts DTO to array.
     */
    public function test_to_array_converts_dto_to_array(): void
    {
        $dto = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/123',
            variantId: 'gid://shopify/ProductVariant/456',
            productId: 'gid://shopify/Product/789',
            title: 'Test Product - Default',
            quantity: 2,
            price: [
                'amount' => '29.99',
                'currency' => 'GBP',
            ],
            image: 'https://example.com/image.jpg',
            attributes: [
                ['key' => 'color', 'value' => 'blue'],
            ],
        );

        $array = $dto->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('gid://shopify/CartLine/123', $array['id']);
        $this->assertEquals('gid://shopify/ProductVariant/456', $array['variantId']);
        $this->assertEquals('gid://shopify/Product/789', $array['productId']);
        $this->assertEquals('Test Product - Default', $array['title']);
        $this->assertEquals(2, $array['quantity']);
        $this->assertIsArray($array['price']);
        $this->assertEquals('29.99', $array['price']['amount']);
        $this->assertEquals('GBP', $array['price']['currency']);
        $this->assertEquals('https://example.com/image.jpg', $array['image']);
        $this->assertIsArray($array['attributes']);
        $this->assertCount(1, $array['attributes']);
    }

    /**
     * Test toArray() handles null image.
     */
    public function test_to_array_handles_null_image(): void
    {
        $dto = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/123',
            variantId: 'gid://shopify/ProductVariant/456',
            productId: 'gid://shopify/Product/789',
            title: 'Test Product',
            quantity: 1,
            price: ['amount' => '29.99', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        $array = $dto->toArray();

        $this->assertNull($array['image']);
    }

    /**
     * Test toArray() handles empty attributes.
     */
    public function test_to_array_handles_empty_attributes(): void
    {
        $dto = new CartLineItemDTO(
            id: 'gid://shopify/CartLine/123',
            variantId: 'gid://shopify/ProductVariant/456',
            productId: 'gid://shopify/Product/789',
            title: 'Test Product',
            quantity: 1,
            price: ['amount' => '29.99', 'currency' => 'GBP'],
            image: null,
            attributes: [],
        );

        $array = $dto->toArray();

        $this->assertIsArray($array['attributes']);
        $this->assertEmpty($array['attributes']);
    }
}
