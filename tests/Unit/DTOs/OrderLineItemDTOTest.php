<?php

namespace Tests\Unit\DTOs;

use App\DTOs\Order\OrderLineItemDTO;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class OrderLineItemDTOTest extends TestCase
{
    /**
     * Test that OrderLineItemDTO can be instantiated with valid data.
     */
    public function test_can_create_order_line_item_dto_with_valid_data(): void
    {
        $dto = new OrderLineItemDTO(
            title: 'Test Product - Default',
            quantity: 2,
            discountedTotalPrice: ['amount' => '59.98', 'currency' => 'GBP'],
            variantId: 'gid://shopify/ProductVariant/456',
            variantTitle: 'Default',
            image: 'https://example.com/image.jpg',
            productTitle: 'Test Product',
            productHandle: 'test-product',
        );

        $this->assertEquals('Test Product - Default', $dto->title);
        $this->assertEquals(2, $dto->quantity);
        $this->assertEquals('59.98', $dto->discountedTotalPrice['amount']);
        $this->assertEquals('GBP', $dto->discountedTotalPrice['currency']);
        $this->assertEquals('gid://shopify/ProductVariant/456', $dto->variantId);
        $this->assertEquals('Default', $dto->variantTitle);
        $this->assertEquals('https://example.com/image.jpg', $dto->image);
        $this->assertEquals('Test Product', $dto->productTitle);
        $this->assertEquals('test-product', $dto->productHandle);
    }

    /**
     * Test that OrderLineItemDTO throws exception when title is empty.
     */
    public function test_throws_exception_when_title_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Line item title is required');

        new OrderLineItemDTO(
            title: '',
            quantity: 1,
            discountedTotalPrice: ['amount' => '29.99', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );
    }

    /**
     * Test that OrderLineItemDTO throws exception when quantity is not positive.
     */
    public function test_throws_exception_when_quantity_is_not_positive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be positive');

        new OrderLineItemDTO(
            title: 'Test Product',
            quantity: 0,
            discountedTotalPrice: ['amount' => '0.00', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );
    }

    /**
     * Test that OrderLineItemDTO throws exception when quantity is negative.
     */
    public function test_throws_exception_when_quantity_is_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Quantity must be positive');

        new OrderLineItemDTO(
            title: 'Test Product',
            quantity: -1,
            discountedTotalPrice: ['amount' => '29.99', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );
    }

    /**
     * Test that OrderLineItemDTO can be created with null optional fields.
     */
    public function test_can_create_line_item_with_null_optional_fields(): void
    {
        $dto = new OrderLineItemDTO(
            title: 'Test Product',
            quantity: 1,
            discountedTotalPrice: ['amount' => '29.99', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $this->assertNull($dto->variantId);
        $this->assertNull($dto->variantTitle);
        $this->assertNull($dto->image);
        $this->assertNull($dto->productTitle);
        $this->assertNull($dto->productHandle);
    }

    /**
     * Test fromShopifyResponse() creates DTO from Shopify data with full variant info.
     */
    public function test_from_shopify_response_creates_dto_with_full_variant_info(): void
    {
        $shopifyData = [
            'title' => 'Test Product - Default',
            'quantity' => 2,
            'discountedTotalPrice' => [
                'amount' => '59.98',
                'currencyCode' => 'GBP',
            ],
            'variant' => [
                'id' => 'gid://shopify/ProductVariant/456',
                'title' => 'Default',
                'image' => [
                    'url' => 'https://example.com/image.jpg',
                ],
                'product' => [
                    'title' => 'Test Product',
                    'handle' => 'test-product',
                ],
            ],
        ];

        $dto = OrderLineItemDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('Test Product - Default', $dto->title);
        $this->assertEquals(2, $dto->quantity);
        $this->assertEquals('59.98', $dto->discountedTotalPrice['amount']);
        $this->assertEquals('GBP', $dto->discountedTotalPrice['currency']);
        $this->assertEquals('gid://shopify/ProductVariant/456', $dto->variantId);
        $this->assertEquals('Default', $dto->variantTitle);
        $this->assertEquals('https://example.com/image.jpg', $dto->image);
        $this->assertEquals('Test Product', $dto->productTitle);
        $this->assertEquals('test-product', $dto->productHandle);
    }

    /**
     * Test fromShopifyResponse() creates DTO from Shopify data without variant info.
     */
    public function test_from_shopify_response_creates_dto_without_variant_info(): void
    {
        $shopifyData = [
            'title' => 'Test Product',
            'quantity' => 1,
            'discountedTotalPrice' => [
                'amount' => '29.99',
                'currencyCode' => 'GBP',
            ],
        ];

        $dto = OrderLineItemDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('Test Product', $dto->title);
        $this->assertEquals(1, $dto->quantity);
        $this->assertEquals('29.99', $dto->discountedTotalPrice['amount']);
        $this->assertNull($dto->variantId);
        $this->assertNull($dto->variantTitle);
        $this->assertNull($dto->image);
        $this->assertNull($dto->productTitle);
        $this->assertNull($dto->productHandle);
    }

    /**
     * Test fromShopifyResponse() handles missing image in variant.
     */
    public function test_from_shopify_response_handles_missing_image(): void
    {
        $shopifyData = [
            'title' => 'Test Product',
            'quantity' => 1,
            'discountedTotalPrice' => [
                'amount' => '29.99',
                'currencyCode' => 'GBP',
            ],
            'variant' => [
                'id' => 'gid://shopify/ProductVariant/456',
                'title' => 'Default',
                'product' => [
                    'title' => 'Test Product',
                    'handle' => 'test-product',
                ],
            ],
        ];

        $dto = OrderLineItemDTO::fromShopifyResponse($shopifyData);

        $this->assertNull($dto->image);
        $this->assertEquals('gid://shopify/ProductVariant/456', $dto->variantId);
    }

    /**
     * Test fromShopifyResponse() handles missing product in variant.
     */
    public function test_from_shopify_response_handles_missing_product(): void
    {
        $shopifyData = [
            'title' => 'Test Product',
            'quantity' => 1,
            'discountedTotalPrice' => [
                'amount' => '29.99',
                'currencyCode' => 'GBP',
            ],
            'variant' => [
                'id' => 'gid://shopify/ProductVariant/456',
                'title' => 'Default',
            ],
        ];

        $dto = OrderLineItemDTO::fromShopifyResponse($shopifyData);

        $this->assertNull($dto->productTitle);
        $this->assertNull($dto->productHandle);
        $this->assertEquals('gid://shopify/ProductVariant/456', $dto->variantId);
    }

    /**
     * Test fromShopifyResponse() uses default currency when missing.
     */
    public function test_from_shopify_response_uses_default_currency_when_missing(): void
    {
        $shopifyData = [
            'title' => 'Test Product',
            'quantity' => 1,
            'discountedTotalPrice' => [
                'amount' => '29.99',
            ],
        ];

        $dto = OrderLineItemDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('GBP', $dto->discountedTotalPrice['currency']);
    }

    /**
     * Test fromShopifyResponse() uses default amount when missing.
     */
    public function test_from_shopify_response_uses_default_amount_when_missing(): void
    {
        $shopifyData = [
            'title' => 'Test Product',
            'quantity' => 1,
            'discountedTotalPrice' => [],
        ];

        $dto = OrderLineItemDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('0.00', $dto->discountedTotalPrice['amount']);
        $this->assertEquals('GBP', $dto->discountedTotalPrice['currency']);
    }

    /**
     * Test toArray() converts DTO to array.
     */
    public function test_to_array_converts_dto_to_array(): void
    {
        $dto = new OrderLineItemDTO(
            title: 'Test Product - Default',
            quantity: 2,
            discountedTotalPrice: ['amount' => '59.98', 'currency' => 'GBP'],
            variantId: 'gid://shopify/ProductVariant/456',
            variantTitle: 'Default',
            image: 'https://example.com/image.jpg',
            productTitle: 'Test Product',
            productHandle: 'test-product',
        );

        $array = $dto->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('Test Product - Default', $array['title']);
        $this->assertEquals(2, $array['quantity']);
        $this->assertIsArray($array['discountedTotalPrice']);
        $this->assertEquals('59.98', $array['discountedTotalPrice']['amount']);
        $this->assertEquals('GBP', $array['discountedTotalPrice']['currency']);
        $this->assertEquals('gid://shopify/ProductVariant/456', $array['variantId']);
        $this->assertEquals('Default', $array['variantTitle']);
        $this->assertEquals('https://example.com/image.jpg', $array['image']);
        $this->assertEquals('Test Product', $array['productTitle']);
        $this->assertEquals('test-product', $array['productHandle']);
    }

    /**
     * Test toArray() handles null optional fields.
     */
    public function test_to_array_handles_null_optional_fields(): void
    {
        $dto = new OrderLineItemDTO(
            title: 'Test Product',
            quantity: 1,
            discountedTotalPrice: ['amount' => '29.99', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $array = $dto->toArray();

        $this->assertNull($array['variantId']);
        $this->assertNull($array['variantTitle']);
        $this->assertNull($array['image']);
        $this->assertNull($array['productTitle']);
        $this->assertNull($array['productHandle']);
    }

    /**
     * Test that line item with quantity 1 is valid.
     */
    public function test_line_item_with_quantity_one_is_valid(): void
    {
        $dto = new OrderLineItemDTO(
            title: 'Test Product',
            quantity: 1,
            discountedTotalPrice: ['amount' => '29.99', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $this->assertEquals(1, $dto->quantity);
    }

    /**
     * Test that line item with large quantity is valid.
     */
    public function test_line_item_with_large_quantity_is_valid(): void
    {
        $dto = new OrderLineItemDTO(
            title: 'Test Product',
            quantity: 999,
            discountedTotalPrice: ['amount' => '29990.01', 'currency' => 'GBP'],
            variantId: null,
            variantTitle: null,
            image: null,
            productTitle: null,
            productHandle: null,
        );

        $this->assertEquals(999, $dto->quantity);
    }
}
