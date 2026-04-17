<?php

namespace Tests\Unit\DTOs;

use App\DTOs\Product\ProductDTO;
use App\DTOs\Product\ProductVariantDTO;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ProductDTOTest extends TestCase
{
    /**
     * Test that ProductDTO can be instantiated with valid data.
     */
    public function test_can_create_product_dto_with_valid_data(): void
    {
        $variant = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/123',
            title: 'Default',
            sku: null,
            price: '29.99',
            currencyCode: 'GBP',
            compareAtPrice: null,
            availableForSale: true,
            quantityAvailable: null,
            image: null,
            selectedOptions: [],
            weight: null,
            weightUnit: null,
        );

        $dto = new ProductDTO(
            id: 'gid://shopify/Product/123',
            title: 'Test Product',
            handle: 'test-product',
            description: 'A test product description',
            vendor: 'Test Vendor',
            productType: 'Test Type',
            tags: ['tag1', 'tag2'],
            availableForSale: true,
            images: [
                ['url' => 'https://example.com/image1.jpg', 'alt' => 'Image 1'],
                ['url' => 'https://example.com/image2.jpg', 'alt' => 'Image 2'],
            ],
            variants: [$variant],
            options: [
                ['name' => 'Size', 'values' => ['Small', 'Medium', 'Large']],
            ],
            publishedAt: '2025-01-01T00:00:00Z',
            updatedAt: '2025-01-20T00:00:00Z',
        );

        $this->assertEquals('gid://shopify/Product/123', $dto->id);
        $this->assertEquals('Test Product', $dto->title);
        $this->assertEquals('test-product', $dto->handle);
        $this->assertEquals('A test product description', $dto->description);
        $this->assertEquals('Test Vendor', $dto->vendor);
        $this->assertEquals('Test Type', $dto->productType);
        $this->assertCount(2, $dto->tags);
        $this->assertTrue($dto->availableForSale);
        $this->assertCount(2, $dto->images);
        $this->assertCount(1, $dto->variants);
        $this->assertInstanceOf(ProductVariantDTO::class, $dto->variants[0]);
        $this->assertCount(1, $dto->options);
    }

    /**
     * Test that ProductDTO throws exception when ID is empty.
     */
    public function test_throws_exception_when_id_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Product ID is required');

        new ProductDTO(
            id: '',
            title: 'Test Product',
            handle: 'test-product',
            description: null,
            vendor: null,
            productType: null,
            tags: [],
            availableForSale: true,
            images: [],
            variants: [],
            options: [],
            publishedAt: null,
            updatedAt: null,
        );
    }

    /**
     * Test that ProductDTO throws exception when title is empty.
     */
    public function test_throws_exception_when_title_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Product title is required');

        new ProductDTO(
            id: 'gid://shopify/Product/123',
            title: '',
            handle: 'test-product',
            description: null,
            vendor: null,
            productType: null,
            tags: [],
            availableForSale: true,
            images: [],
            variants: [],
            options: [],
            publishedAt: null,
            updatedAt: null,
        );
    }

    /**
     * Test that ProductDTO throws exception when handle is empty.
     */
    public function test_throws_exception_when_handle_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Product handle is required');

        new ProductDTO(
            id: 'gid://shopify/Product/123',
            title: 'Test Product',
            handle: '',
            description: null,
            vendor: null,
            productType: null,
            tags: [],
            availableForSale: true,
            images: [],
            variants: [],
            options: [],
            publishedAt: null,
            updatedAt: null,
        );
    }

    /**
     * Test fromShopifyResponse() creates DTO from Shopify data with edges format.
     */
    public function test_from_shopify_response_creates_dto_with_edges_format(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Product/123',
            'title' => 'Test Product',
            'handle' => 'test-product',
            'description' => 'A test product',
            'vendor' => 'Test Vendor',
            'productType' => 'Test Type',
            'tags' => ['tag1', 'tag2'],
            'availableForSale' => true,
            'images' => [
                'edges' => [
                    ['node' => ['url' => 'https://example.com/image1.jpg', 'altText' => 'Image 1']],
                    ['node' => ['url' => 'https://example.com/image2.jpg', 'altText' => 'Image 2']],
                ],
            ],
            'variants' => [
                'edges' => [
                    [
                        'node' => [
                            'id' => 'gid://shopify/ProductVariant/123',
                            'title' => 'Default',
                            'price' => ['amount' => '29.99', 'currencyCode' => 'GBP'],
                            'availableForSale' => true,
                            'selectedOptions' => [],
                        ],
                    ],
                ],
            ],
            'options' => [
                ['name' => 'Size', 'values' => ['Small', 'Medium']],
            ],
            'publishedAt' => '2025-01-01T00:00:00Z',
            'updatedAt' => '2025-01-20T00:00:00Z',
        ];

        $dto = ProductDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/Product/123', $dto->id);
        $this->assertEquals('Test Product', $dto->title);
        $this->assertEquals('test-product', $dto->handle);
        $this->assertEquals('A test product', $dto->description);
        $this->assertEquals('Test Vendor', $dto->vendor);
        $this->assertCount(2, $dto->tags);
        $this->assertCount(2, $dto->images);
        $this->assertCount(1, $dto->variants);
        $this->assertInstanceOf(ProductVariantDTO::class, $dto->variants[0]);
    }

    /**
     * Test fromShopifyResponse() creates DTO from Shopify data without edges format.
     */
    public function test_from_shopify_response_creates_dto_without_edges_format(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Product/123',
            'title' => 'Test Product',
            'handle' => 'test-product',
            'availableForSale' => true,
            'images' => [
                ['url' => 'https://example.com/image1.jpg', 'altText' => 'Image 1'],
            ],
            'variants' => [
                [
                    'id' => 'gid://shopify/ProductVariant/123',
                    'title' => 'Default',
                    'price' => ['amount' => '29.99', 'currencyCode' => 'GBP'],
                    'availableForSale' => true,
                    'selectedOptions' => [],
                ],
            ],
            'options' => [],
        ];

        $dto = ProductDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/Product/123', $dto->id);
        $this->assertEquals('Test Product', $dto->title);
        $this->assertCount(1, $dto->images);
        $this->assertCount(1, $dto->variants);
    }

    /**
     * Test fromShopifyResponse() handles alternative image format with src.
     */
    public function test_from_shopify_response_handles_src_image_format(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Product/123',
            'title' => 'Test Product',
            'handle' => 'test-product',
            'availableForSale' => true,
            'images' => [
                ['src' => 'https://example.com/image1.jpg', 'alt' => 'Image 1'],
            ],
            'variants' => [],
            'options' => [],
        ];

        $dto = ProductDTO::fromShopifyResponse($shopifyData);

        $this->assertCount(1, $dto->images);
        $this->assertEquals('https://example.com/image1.jpg', $dto->images[0]['url']);
        $this->assertEquals('Image 1', $dto->images[0]['alt']);
    }

    /**
     * Test fromShopifyResponse() handles missing optional fields.
     */
    public function test_from_shopify_response_handles_missing_optional_fields(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Product/123',
            'title' => 'Test Product',
            'handle' => 'test-product',
        ];

        $dto = ProductDTO::fromShopifyResponse($shopifyData);

        $this->assertNull($dto->description);
        $this->assertNull($dto->vendor);
        $this->assertNull($dto->productType);
        $this->assertEmpty($dto->tags);
        $this->assertFalse($dto->availableForSale);
        $this->assertEmpty($dto->images);
        $this->assertEmpty($dto->variants);
        $this->assertEmpty($dto->options);
        $this->assertNull($dto->publishedAt);
        $this->assertNull($dto->updatedAt);
    }

    /**
     * Test toArray() converts DTO to array including nested variants.
     */
    public function test_to_array_converts_dto_to_array_with_nested_variants(): void
    {
        $variant = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/123',
            title: 'Default',
            sku: 'SKU-123',
            price: '29.99',
            currencyCode: 'GBP',
            compareAtPrice: null,
            availableForSale: true,
            quantityAvailable: 10,
            image: null,
            selectedOptions: [],
            weight: null,
            weightUnit: null,
        );

        $dto = new ProductDTO(
            id: 'gid://shopify/Product/123',
            title: 'Test Product',
            handle: 'test-product',
            description: 'Description',
            vendor: 'Vendor',
            productType: 'Type',
            tags: ['tag1'],
            availableForSale: true,
            images: [['url' => 'https://example.com/image.jpg', 'alt' => 'Image']],
            variants: [$variant],
            options: [],
            publishedAt: '2025-01-01T00:00:00Z',
            updatedAt: '2025-01-20T00:00:00Z',
        );

        $array = $dto->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('gid://shopify/Product/123', $array['id']);
        $this->assertEquals('Test Product', $array['title']);
        $this->assertEquals('test-product', $array['handle']);
        $this->assertIsArray($array['variants']);
        $this->assertCount(1, $array['variants']);
        $this->assertIsArray($array['variants'][0]);
        $this->assertEquals('gid://shopify/ProductVariant/123', $array['variants'][0]['id']);
        $this->assertEquals('SKU-123', $array['variants'][0]['sku']);
    }

    /**
     * Test toArray() handles null values correctly.
     */
    public function test_to_array_handles_null_values(): void
    {
        $dto = new ProductDTO(
            id: 'gid://shopify/Product/123',
            title: 'Test Product',
            handle: 'test-product',
            description: null,
            vendor: null,
            productType: null,
            tags: [],
            availableForSale: true,
            images: [],
            variants: [],
            options: [],
            publishedAt: null,
            updatedAt: null,
        );

        $array = $dto->toArray();

        $this->assertNull($array['description']);
        $this->assertNull($array['vendor']);
        $this->assertNull($array['productType']);
        $this->assertNull($array['publishedAt']);
        $this->assertNull($array['updatedAt']);
    }

    /**
     * Test toArray() handles empty arrays correctly.
     */
    public function test_to_array_handles_empty_arrays(): void
    {
        $dto = new ProductDTO(
            id: 'gid://shopify/Product/123',
            title: 'Test Product',
            handle: 'test-product',
            description: null,
            vendor: null,
            productType: null,
            tags: [],
            availableForSale: true,
            images: [],
            variants: [],
            options: [],
            publishedAt: null,
            updatedAt: null,
        );

        $array = $dto->toArray();

        $this->assertIsArray($array['tags']);
        $this->assertEmpty($array['tags']);
        $this->assertIsArray($array['images']);
        $this->assertEmpty($array['images']);
        $this->assertIsArray($array['variants']);
        $this->assertEmpty($array['variants']);
        $this->assertIsArray($array['options']);
        $this->assertEmpty($array['options']);
    }
}
