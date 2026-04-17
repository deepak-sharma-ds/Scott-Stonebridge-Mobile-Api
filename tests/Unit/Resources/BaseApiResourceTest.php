<?php

namespace Tests\Unit\Resources;

use App\Http\Resources\Base\BaseApiResource;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Test BaseApiResource transformation methods
 * 
 * Validates: Requirements 5.5, 17.6, 17.7
 */
class BaseApiResourceTest extends TestCase
{
    private BaseApiResource $resource;

    protected function setUp(): void
    {
        parent::setUp();

        // Create a concrete implementation for testing
        $this->resource = new class(['test' => 'data']) extends BaseApiResource {
            public function toArray(Request $request): array
            {
                return ['test' => $this->resource['test']];
            }

            // Expose protected methods for testing
            public function testFlattenEdges($data)
            {
                return $this->flattenEdges($data);
            }

            public function testParseConnection($connection, $key = 'items')
            {
                return $this->parseConnection($connection, $key);
            }

            public function testRemoveInternalFields($data, $fieldsToRemove = [])
            {
                return $this->removeInternalFields($data, $fieldsToRemove);
            }

            public function testExtractPaginationMeta($connection)
            {
                return $this->extractPaginationMeta($connection);
            }

            public function testTransformMoney($moneyData)
            {
                return $this->transformMoney($moneyData);
            }

            public function testTransformImage($imageData)
            {
                return $this->transformImage($imageData);
            }

            public function testTransformImages($imagesData)
            {
                return $this->transformImages($imagesData);
            }
        };
    }

    /** @test */
    public function it_flattens_simple_edge_node_structure()
    {
        $data = [
            'edges' => [
                ['node' => ['id' => '1', 'name' => 'Product 1']],
                ['node' => ['id' => '2', 'name' => 'Product 2']],
            ]
        ];

        $result = $this->resource->testFlattenEdges($data);

        $this->assertEquals([
            ['id' => '1', 'name' => 'Product 1'],
            ['id' => '2', 'name' => 'Product 2'],
        ], $result);
    }

    /** @test */
    public function it_flattens_nested_edge_node_structures()
    {
        $data = [
            'id' => 'product-1',
            'title' => 'Test Product',
            'images' => [
                'edges' => [
                    ['node' => ['url' => 'image1.jpg', 'altText' => 'Image 1']],
                    ['node' => ['url' => 'image2.jpg', 'altText' => 'Image 2']],
                ]
            ],
            'variants' => [
                'edges' => [
                    ['node' => ['id' => 'variant-1', 'title' => 'Small']],
                    ['node' => ['id' => 'variant-2', 'title' => 'Large']],
                ]
            ]
        ];

        $result = $this->resource->testFlattenEdges($data);

        $this->assertEquals([
            'id' => 'product-1',
            'title' => 'Test Product',
            'images' => [
                ['url' => 'image1.jpg', 'altText' => 'Image 1'],
                ['url' => 'image2.jpg', 'altText' => 'Image 2'],
            ],
            'variants' => [
                ['id' => 'variant-1', 'title' => 'Small'],
                ['id' => 'variant-2', 'title' => 'Large'],
            ]
        ], $result);
    }

    /** @test */
    public function it_handles_non_array_data()
    {
        $this->assertEquals('string', $this->resource->testFlattenEdges('string'));
        $this->assertEquals(123, $this->resource->testFlattenEdges(123));
        $this->assertEquals(null, $this->resource->testFlattenEdges(null));
    }

    /** @test */
    public function it_handles_empty_edges()
    {
        $data = ['edges' => []];
        $result = $this->resource->testFlattenEdges($data);
        $this->assertEquals([], $result);
    }

    /** @test */
    public function it_parses_connection_with_pagination()
    {
        $connection = [
            'edges' => [
                ['node' => ['id' => '1', 'name' => 'Item 1']],
                ['node' => ['id' => '2', 'name' => 'Item 2']],
            ],
            'pageInfo' => [
                'endCursor' => 'cursor123',
                'hasNextPage' => true,
            ]
        ];

        $result = $this->resource->testParseConnection($connection);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('next_cursor', $result);
        $this->assertArrayHasKey('has_more', $result);
        $this->assertCount(2, $result['items']);
        $this->assertEquals('cursor123', $result['next_cursor']);
        $this->assertTrue($result['has_more']);
    }

    /** @test */
    public function it_parses_connection_with_custom_key()
    {
        $connection = [
            'edges' => [
                ['node' => ['id' => '1']],
            ],
            'pageInfo' => [
                'endCursor' => null,
                'hasNextPage' => false,
            ]
        ];

        $result = $this->resource->testParseConnection($connection, 'products');

        $this->assertArrayHasKey('products', $result);
        $this->assertCount(1, $result['products']);
    }

    /** @test */
    public function it_handles_null_connection()
    {
        $result = $this->resource->testParseConnection(null);

        $this->assertEquals([
            'items' => [],
            'next_cursor' => null,
            'has_more' => false,
        ], $result);
    }

    /** @test */
    public function it_handles_malformed_connection()
    {
        $result = $this->resource->testParseConnection(['invalid' => 'data']);

        $this->assertEquals([
            'items' => [],
            'next_cursor' => null,
            'has_more' => false,
        ], $result);
    }

    /** @test */
    public function it_removes_internal_fields()
    {
        $data = [
            'id' => '1',
            'name' => 'Product',
            '__typename' => 'Product',
            'edges' => [],
            'nodes' => [],
            'pageInfo' => [],
        ];

        $result = $this->resource->testRemoveInternalFields($data);

        $this->assertEquals([
            'id' => '1',
            'name' => 'Product',
        ], $result);
    }

    /** @test */
    public function it_removes_custom_fields()
    {
        $data = [
            'id' => '1',
            'name' => 'Product',
            'internalField' => 'value',
            'anotherInternal' => 'value2',
        ];

        $result = $this->resource->testRemoveInternalFields($data, ['internalField', 'anotherInternal']);

        $this->assertEquals([
            'id' => '1',
            'name' => 'Product',
        ], $result);
    }

    /** @test */
    public function it_extracts_pagination_metadata()
    {
        $connection = [
            'pageInfo' => [
                'endCursor' => 'cursor456',
                'hasNextPage' => true,
            ],
            'totalCount' => 100,
        ];

        $result = $this->resource->testExtractPaginationMeta($connection);

        $this->assertEquals([
            'next_cursor' => 'cursor456',
            'has_more' => true,
            'total_count' => 100,
        ], $result);
    }

    /** @test */
    public function it_handles_null_pagination_metadata()
    {
        $result = $this->resource->testExtractPaginationMeta(null);

        $this->assertEquals([
            'next_cursor' => null,
            'has_more' => false,
            'total_count' => null,
        ], $result);
    }

    /** @test */
    public function it_transforms_money_data()
    {
        $moneyData = [
            'amount' => '99.99',
            'currencyCode' => 'USD',
        ];

        $result = $this->resource->testTransformMoney($moneyData);

        $this->assertEquals([
            'amount' => '99.99',
            'currency' => 'USD',
        ], $result);
    }

    /** @test */
    public function it_uses_default_currency_when_missing()
    {
        $moneyData = [
            'amount' => '99.99',
        ];

        $result = $this->resource->testTransformMoney($moneyData);

        $this->assertEquals([
            'amount' => '99.99',
            'currency' => 'GBP',
        ], $result);
    }

    /** @test */
    public function it_handles_null_money_data()
    {
        $result = $this->resource->testTransformMoney(null);
        $this->assertNull($result);
    }

    /** @test */
    public function it_transforms_image_data()
    {
        $imageData = [
            'url' => 'https://example.com/image.jpg',
            'altText' => 'Product Image',
        ];

        $result = $this->resource->testTransformImage($imageData);

        $this->assertEquals([
            'url' => 'https://example.com/image.jpg',
            'alt' => 'Product Image',
        ], $result);
    }

    /** @test */
    public function it_handles_image_without_alt_text()
    {
        $imageData = [
            'url' => 'https://example.com/image.jpg',
        ];

        $result = $this->resource->testTransformImage($imageData);

        $this->assertEquals([
            'url' => 'https://example.com/image.jpg',
            'alt' => null,
        ], $result);
    }

    /** @test */
    public function it_handles_null_image_data()
    {
        $result = $this->resource->testTransformImage(null);
        $this->assertNull($result);
    }

    /** @test */
    public function it_transforms_array_of_images()
    {
        $imagesData = [
            ['url' => 'image1.jpg', 'altText' => 'Image 1'],
            ['url' => 'image2.jpg', 'altText' => 'Image 2'],
        ];

        $result = $this->resource->testTransformImages($imagesData);

        $this->assertCount(2, $result);
        $this->assertEquals('image1.jpg', $result[0]['url']);
        $this->assertEquals('Image 1', $result[0]['alt']);
    }

    /** @test */
    public function it_transforms_images_from_connection_structure()
    {
        $imagesData = [
            'edges' => [
                ['node' => ['url' => 'image1.jpg', 'altText' => 'Image 1']],
                ['node' => ['url' => 'image2.jpg', 'altText' => 'Image 2']],
            ]
        ];

        $result = $this->resource->testTransformImages($imagesData);

        $this->assertCount(2, $result);
        $this->assertEquals('image1.jpg', $result[0]['url']);
    }

    /** @test */
    public function it_handles_null_images_data()
    {
        $result = $this->resource->testTransformImages(null);
        $this->assertEquals([], $result);
    }

    /** @test */
    public function it_handles_empty_images_array()
    {
        $result = $this->resource->testTransformImages([]);
        $this->assertEquals([], $result);
    }
}
