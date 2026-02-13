<?php

namespace Tests\Unit\Resources;

use App\DTOs\Product\ProductDTO;
use App\DTOs\Product\ProductVariantDTO;
use App\Http\Resources\Product\ProductResource;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ProductResource Unit Tests
 * 
 * Tests transformation logic from ProductDTO to API response format.
 * Validates field mapping, nested resource handling, and edge cases.
 */
class ProductResourceTest extends TestCase
{
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = Request::create('/test', 'GET');
    }

    /** @test */
    public function it_transforms_product_dto_to_array(): void
    {
        // Arrange
        $variantDTO = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/123',
            title: 'Small / Red',
            sku: 'TEST-SKU-001',
            price: '29.99',
            currencyCode: 'GBP',
            compareAtPrice: '39.99',
            availableForSale: true,
            quantityAvailable: 10,
            image: 'https://example.com/image.jpg',
            selectedOptions: [
                ['name' => 'Size', 'value' => 'Small'],
                ['name' => 'Color', 'value' => 'Red'],
            ],
            weight: 0.5,
            weightUnit: 'kg',
        );

        $productDTO = new ProductDTO(
            id: 'gid://shopify/Product/456',
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
            variants: [$variantDTO],
            options: [
                ['name' => 'Size', 'values' => ['Small', 'Medium', 'Large']],
                ['name' => 'Color', 'values' => ['Red', 'Blue']],
            ],
            publishedAt: '2025-01-01T00:00:00Z',
            updatedAt: '2025-01-20T00:00:00Z',
        );

        // Act
        $resource = new ProductResource($productDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('gid://shopify/Product/456', $result['id']);
        $this->assertEquals('Test Product', $result['title']);
        $this->assertEquals('test-product', $result['handle']);
        $this->assertEquals('A test product description', $result['description']);
        $this->assertEquals('Test Vendor', $result['vendor']);
        $this->assertEquals('Test Type', $result['product_type']);
        $this->assertEquals(['tag1', 'tag2'], $result['tags']);
        $this->assertTrue($result['available_for_sale']);
        $this->assertCount(2, $result['images']);
        $this->assertCount(2, $result['options']);
        $this->assertEquals('2025-01-01T00:00:00Z', $result['published_at']);
        $this->assertEquals('2025-01-20T00:00:00Z', $result['updated_at']);
    }

    /** @test */
    public function it_transforms_nested_variants_using_variant_resource(): void
    {
        // Arrange
        $variantDTO = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/789',
            title: 'Default',
            sku: null,
            price: '19.99',
            currencyCode: 'USD',
            compareAtPrice: null,
            availableForSale: true,
            quantityAvailable: 5,
            image: null,
            selectedOptions: [],
            weight: null,
            weightUnit: null,
        );

        $productDTO = new ProductDTO(
            id: 'gid://shopify/Product/999',
            title: 'Simple Product',
            handle: 'simple-product',
            description: null,
            vendor: null,
            productType: null,
            tags: [],
            availableForSale: true,
            images: [],
            variants: [$variantDTO],
            options: [],
            publishedAt: null,
            updatedAt: null,
        );

        // Act
        $resource = new ProductResource($productDTO);
        $result = $resource->toArray($this->request);

        // Assert - variants is a ResourceCollection, resolve it to array
        $this->assertInstanceOf(\Illuminate\Http\Resources\Json\AnonymousResourceCollection::class, $result['variants']);
        $variantsArray = $result['variants']->resolve($this->request);
        
        $this->assertIsArray($variantsArray);
        $this->assertCount(1, $variantsArray);
        
        $variant = $variantsArray[0];
        $this->assertEquals('gid://shopify/ProductVariant/789', $variant['id']);
        $this->assertEquals('Default', $variant['title']);
        $this->assertNull($variant['sku']);
        $this->assertEquals('19.99', $variant['price']);
        $this->assertEquals('USD', $variant['currency_code']);
    }

    /** @test */
    public function it_handles_product_with_null_optional_fields(): void
    {
        // Arrange
        $productDTO = new ProductDTO(
            id: 'gid://shopify/Product/111',
            title: 'Minimal Product',
            handle: 'minimal-product',
            description: null,
            vendor: null,
            productType: null,
            tags: [],
            availableForSale: false,
            images: [],
            variants: [],
            options: [],
            publishedAt: null,
            updatedAt: null,
        );

        // Act
        $resource = new ProductResource($productDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertNull($result['description']);
        $this->assertNull($result['vendor']);
        $this->assertNull($result['product_type']);
        $this->assertEmpty($result['tags']);
        $this->assertFalse($result['available_for_sale']);
        $this->assertEmpty($result['images']);
        $this->assertEmpty($result['variants']);
        $this->assertEmpty($result['options']);
        $this->assertNull($result['published_at']);
        $this->assertNull($result['updated_at']);
    }

    /** @test */
    public function it_handles_product_with_multiple_variants(): void
    {
        // Arrange
        $variant1 = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/1',
            title: 'Small',
            sku: 'SKU-1',
            price: '10.00',
            currencyCode: 'GBP',
            compareAtPrice: null,
            availableForSale: true,
            quantityAvailable: 5,
            image: null,
            selectedOptions: [['name' => 'Size', 'value' => 'Small']],
            weight: null,
            weightUnit: null,
        );

        $variant2 = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/2',
            title: 'Medium',
            sku: 'SKU-2',
            price: '15.00',
            currencyCode: 'GBP',
            compareAtPrice: '20.00',
            availableForSale: true,
            quantityAvailable: 3,
            image: null,
            selectedOptions: [['name' => 'Size', 'value' => 'Medium']],
            weight: null,
            weightUnit: null,
        );

        $productDTO = new ProductDTO(
            id: 'gid://shopify/Product/222',
            title: 'Multi-Variant Product',
            handle: 'multi-variant',
            description: 'Product with multiple variants',
            vendor: 'Vendor',
            productType: 'Type',
            tags: ['multi'],
            availableForSale: true,
            images: [],
            variants: [$variant1, $variant2],
            options: [['name' => 'Size', 'values' => ['Small', 'Medium']]],
            publishedAt: '2025-01-01T00:00:00Z',
            updatedAt: '2025-01-20T00:00:00Z',
        );

        // Act
        $resource = new ProductResource($productDTO);
        $result = $resource->toArray($this->request);

        // Assert - resolve the ResourceCollection to array
        $variantsArray = $result['variants']->resolve($this->request);
        
        $this->assertCount(2, $variantsArray);
        $this->assertEquals('Small', $variantsArray[0]['title']);
        $this->assertEquals('10.00', $variantsArray[0]['price']);
        $this->assertEquals('Medium', $variantsArray[1]['title']);
        $this->assertEquals('15.00', $variantsArray[1]['price']);
        $this->assertEquals('20.00', $variantsArray[1]['compare_at_price']);
    }

    /** @test */
    public function it_preserves_image_structure(): void
    {
        // Arrange
        $productDTO = new ProductDTO(
            id: 'gid://shopify/Product/333',
            title: 'Product with Images',
            handle: 'product-images',
            description: null,
            vendor: null,
            productType: null,
            tags: [],
            availableForSale: true,
            images: [
                ['url' => 'https://example.com/img1.jpg', 'alt' => 'First Image'],
                ['url' => 'https://example.com/img2.jpg', 'alt' => null],
                ['url' => 'https://example.com/img3.jpg', 'alt' => 'Third Image'],
            ],
            variants: [],
            options: [],
            publishedAt: null,
            updatedAt: null,
        );

        // Act
        $resource = new ProductResource($productDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertCount(3, $result['images']);
        $this->assertEquals('https://example.com/img1.jpg', $result['images'][0]['url']);
        $this->assertEquals('First Image', $result['images'][0]['alt']);
        $this->assertEquals('https://example.com/img2.jpg', $result['images'][1]['url']);
        $this->assertNull($result['images'][1]['alt']);
        $this->assertEquals('https://example.com/img3.jpg', $result['images'][2]['url']);
        $this->assertEquals('Third Image', $result['images'][2]['alt']);
    }

    /** @test */
    public function it_uses_snake_case_for_field_names(): void
    {
        // Arrange
        $productDTO = new ProductDTO(
            id: 'gid://shopify/Product/444',
            title: 'Test',
            handle: 'test',
            description: null,
            vendor: null,
            productType: 'Electronics',
            tags: [],
            availableForSale: true,
            images: [],
            variants: [],
            options: [],
            publishedAt: '2025-01-01T00:00:00Z',
            updatedAt: '2025-01-20T00:00:00Z',
        );

        // Act
        $resource = new ProductResource($productDTO);
        $result = $resource->toArray($this->request);

        // Assert - verify snake_case field names
        $this->assertArrayHasKey('product_type', $result);
        $this->assertArrayHasKey('available_for_sale', $result);
        $this->assertArrayHasKey('published_at', $result);
        $this->assertArrayHasKey('updated_at', $result);
        
        // Verify camelCase fields are NOT present
        $this->assertArrayNotHasKey('productType', $result);
        $this->assertArrayNotHasKey('availableForSale', $result);
        $this->assertArrayNotHasKey('publishedAt', $result);
        $this->assertArrayNotHasKey('updatedAt', $result);
    }
}
