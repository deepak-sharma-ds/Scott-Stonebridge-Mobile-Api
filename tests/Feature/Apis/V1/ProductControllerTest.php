<?php

namespace Tests\Feature\Apis\V1;

use App\Contracts\Shopify\StorefrontServiceInterface;
use App\DTOs\Shopify\MoneyDTO;
use App\DTOs\Shopify\ProductDTO;
use App\DTOs\Shopify\VariantDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class ProductControllerTest extends TestCase
{
    private $storefrontService;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->storefrontService = Mockery::mock(StorefrontServiceInterface::class);
        $this->app->instance(StorefrontServiceInterface::class, $this->storefrontService);
    }

    public function test_get_products_list_returns_successful_response()
    {
        // Arrange
        $products = new Collection([
            new ProductDTO(
                id: 'gid://shopify/Product/1',
                title: 'Test Product',
                handle: 'test-product',
                description: 'Description',
                descriptionHtml: '<p>Description</p>',
                vendor: 'Vendor',
                productType: 'Type',
                tags: ['tag1'],
                images: new Collection(),
                variants: new Collection([
                    new VariantDTO(
                        id: 'gid://shopify/ProductVariant/1',
                        title: 'Default',
                        sku: 'SKU-1',
                        price: new MoneyDTO(10.00, 'USD'),
                        compareAtPrice: null,
                        availableForSale: true,
                        quantityAvailable: 10,
                        weight: 1.0,
                        weightUnit: 'kg',
                        image: null,
                        selectedOptions: []
                    )
                ]),
                options: [],
                availableForSale: true,
                createdAt: new \DateTimeImmutable(),
                updatedAt: new \DateTimeImmutable()
            )
        ]);

        $this->storefrontService
            ->shouldReceive('getProducts')
            ->once()
            ->withArgs(function ($limit, $cursor, $collection, $query, $country) {
                return $country === 'GB'; // Verify currency middleware worked
            })
            ->andReturn($products);

        // Act
        $response = $this->get('/api/v1/products?country=GB');

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    [
                        'id' => 'gid://shopify/Product/1',
                        'title' => 'Test Product',
                        'variants' => [
                            [
                                'price' => [
                                    'amount' => 10.00,
                                    'currency' => 'USD'
                                ]
                            ]
                        ]
                    ]
                ]
            ]);
    }

    public function test_get_product_details_returns_successful_response()
    {
        // Arrange
        $product = new ProductDTO(
            id: 'gid://shopify/Product/1',
            title: 'Test Product',
            handle: 'test-product',
            description: 'Description',
            descriptionHtml: '<p>Description</p>',
            vendor: 'Vendor',
            productType: 'Type',
            tags: ['tag1'],
            images: new Collection(),
            variants: new Collection(),
            options: [],
            availableForSale: true,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable()
        );

        $this->storefrontService
            ->shouldReceive('getProductByHandle')
            ->once()
            ->with('test-product', 'US')
            ->andReturn($product);

        // Act
        $response = $this->get('/api/v1/products/test-product');

        // Assert
        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
                'data' => [
                    'handle' => 'test-product',
                    'title' => 'Test Product'
                ]
            ]);
    }

    public function test_get_product_returns_404_when_not_found()
    {
        // Arrange
        $this->storefrontService
            ->shouldReceive('getProductByHandle')
            ->once()
            ->with('unknown-product', 'US')
            ->andReturn(null);

        // Act
        $response = $this->get('/api/v1/products/unknown-product');

        // Assert
        $response->assertStatus(404)
            ->assertJson([
                'success' => false,
                'message' => 'Product not found'
            ]);
    }
}
