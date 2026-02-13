<?php

namespace Tests\Unit\Traits;

use App\Traits\ApiResponse;
use Illuminate\Http\JsonResponse;
use Tests\TestCase;

class ApiResponseTest extends TestCase
{
    use ApiResponse;

    /** @test */
    public function it_creates_success_response_with_standard_format()
    {
        $response = $this->successResponse('Operation successful', ['id' => 123]);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertTrue($data['success']);
        $this->assertEquals('Operation successful', $data['message']);
        $this->assertEquals(['id' => 123], $data['data']);
        $this->assertArrayHasKey('meta', $data);
        $this->assertArrayHasKey('timestamp', $data['meta']);
    }

    /** @test */
    public function it_creates_error_response_with_standard_format()
    {
        $response = $this->errorResponse('Operation failed', [], ['error_code' => 'NOT_FOUND'], 404);

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(404, $response->getStatusCode());

        $data = $response->getData(true);
        $this->assertFalse($data['success']);
        $this->assertEquals('Operation failed', $data['message']);
        $this->assertEquals([], $data['data']);
        $this->assertArrayHasKey('meta', $data);
        $this->assertEquals('NOT_FOUND', $data['meta']['error_code']);
    }

    /** @test */
    public function it_includes_custom_metadata_in_responses()
    {
        $meta = [
            'correlation_id' => 'abc-123',
            'version' => 'v1',
        ];

        $response = $this->successResponse('Success', [], $meta);
        $data = $response->getData(true);

        $this->assertEquals('abc-123', $data['meta']['correlation_id']);
        $this->assertEquals('v1', $data['meta']['version']);
        $this->assertArrayHasKey('timestamp', $data['meta']);
    }

    /** @test */
    public function it_parses_shopify_connection_with_edges_and_nodes()
    {
        $connection = [
            'edges' => [
                ['node' => ['id' => '1', 'title' => 'Product 1']],
                ['node' => ['id' => '2', 'title' => 'Product 2']],
                ['node' => ['id' => '3', 'title' => 'Product 3']],
            ],
            'pageInfo' => [
                'endCursor' => 'cursor_abc123',
                'hasNextPage' => true,
            ],
        ];

        $result = $this->parseConnection($connection);

        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('pagination', $result);
        $this->assertCount(3, $result['items']);
        $this->assertEquals('1', $result['items'][0]['id']);
        $this->assertEquals('Product 1', $result['items'][0]['title']);
        $this->assertEquals('cursor_abc123', $result['pagination']['next_cursor']);
        $this->assertTrue($result['pagination']['has_more']);
    }

    /** @test */
    public function it_handles_empty_connection()
    {
        $result = $this->parseConnection(null);

        $this->assertEquals([], $result['items']);
        $this->assertNull($result['pagination']['next_cursor']);
        $this->assertFalse($result['pagination']['has_more']);
    }

    /** @test */
    public function it_handles_connection_without_edges()
    {
        $connection = ['pageInfo' => ['hasNextPage' => false]];

        $result = $this->parseConnection($connection);

        $this->assertEquals([], $result['items']);
        $this->assertNull($result['pagination']['next_cursor']);
        $this->assertFalse($result['pagination']['has_more']);
    }

    /** @test */
    public function it_filters_null_nodes_from_edges()
    {
        $connection = [
            'edges' => [
                ['node' => ['id' => '1', 'title' => 'Product 1']],
                ['cursor' => 'abc'], // No node
                ['node' => null], // Null node
                ['node' => ['id' => '2', 'title' => 'Product 2']],
            ],
            'pageInfo' => ['hasNextPage' => false],
        ];

        $result = $this->parseConnection($connection);

        $this->assertCount(2, $result['items']);
        $this->assertEquals('1', $result['items'][0]['id']);
        $this->assertEquals('2', $result['items'][1]['id']);
    }

    /** @test */
    public function it_uses_custom_items_key()
    {
        $connection = [
            'edges' => [
                ['node' => ['id' => '1']],
            ],
            'pageInfo' => ['hasNextPage' => false],
        ];

        $result = $this->parseConnection($connection, 'products');

        $this->assertArrayHasKey('products', $result);
        $this->assertArrayNotHasKey('items', $result);
        $this->assertCount(1, $result['products']);
    }

    /** @test */
    public function it_parses_edges_from_nested_path()
    {
        $data = [
            'data' => [
                'products' => [
                    'edges' => [
                        ['node' => ['id' => '1', 'title' => 'Product 1']],
                        ['node' => ['id' => '2', 'title' => 'Product 2']],
                    ],
                    'pageInfo' => [
                        'endCursor' => 'cursor_xyz',
                        'hasNextPage' => false,
                    ],
                ],
            ],
        ];

        $result = $this->parseEdges($data, 'data.products');

        $this->assertCount(2, $result['items']);
        $this->assertEquals('cursor_xyz', $result['pagination']['next_cursor']);
        $this->assertFalse($result['pagination']['has_more']);
    }

    /** @test */
    public function it_recursively_flattens_nested_edges()
    {
        $data = [
            'id' => 'product_1',
            'title' => 'Product',
            'images' => [
                'edges' => [
                    ['node' => ['url' => 'image1.jpg', 'alt' => 'Image 1']],
                    ['node' => ['url' => 'image2.jpg', 'alt' => 'Image 2']],
                ],
            ],
            'variants' => [
                'edges' => [
                    [
                        'node' => [
                            'id' => 'variant_1',
                            'title' => 'Small',
                            'metafields' => [
                                'edges' => [
                                    ['node' => ['key' => 'size', 'value' => 'S']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];

        $result = $this->flattenEdges($data);

        // Check top-level fields preserved
        $this->assertEquals('product_1', $result['id']);
        $this->assertEquals('Product', $result['title']);

        // Check images flattened
        $this->assertIsArray($result['images']);
        $this->assertCount(2, $result['images']);
        $this->assertEquals('image1.jpg', $result['images'][0]['url']);
        $this->assertEquals('Image 1', $result['images'][0]['alt']);

        // Check variants flattened
        $this->assertIsArray($result['variants']);
        $this->assertCount(1, $result['variants']);
        $this->assertEquals('variant_1', $result['variants'][0]['id']);

        // Check nested metafields flattened
        $this->assertIsArray($result['variants'][0]['metafields']);
        $this->assertCount(1, $result['variants'][0]['metafields']);
        $this->assertEquals('size', $result['variants'][0]['metafields'][0]['key']);
    }

    /** @test */
    public function it_flattens_array_of_objects()
    {
        $data = [
            ['id' => '1', 'name' => 'Item 1'],
            ['id' => '2', 'name' => 'Item 2'],
        ];

        $result = $this->flattenEdges($data);

        $this->assertCount(2, $result);
        $this->assertEquals('1', $result[0]['id']);
        $this->assertEquals('2', $result[1]['id']);
    }

    /** @test */
    public function it_handles_non_array_data_in_flatten()
    {
        $this->assertEquals('string', $this->flattenEdges('string'));
        $this->assertEquals(123, $this->flattenEdges(123));
        $this->assertNull($this->flattenEdges(null));
    }

    /** @test */
    public function it_formats_pagination_metadata()
    {
        $pagination = $this->formatPagination('cursor_123', true, 50);

        $this->assertEquals('cursor_123', $pagination['next_cursor']);
        $this->assertTrue($pagination['has_more']);
        $this->assertEquals(50, $pagination['total_count']);
    }

    /** @test */
    public function it_formats_pagination_without_total_count()
    {
        $pagination = $this->formatPagination('cursor_456', false);

        $this->assertEquals('cursor_456', $pagination['next_cursor']);
        $this->assertFalse($pagination['has_more']);
        $this->assertArrayNotHasKey('total_count', $pagination);
    }

    /** @test */
    public function it_removes_shopify_internal_fields()
    {
        $data = [
            'id' => '123',
            'title' => 'Product',
            '__typename' => 'Product',
            'admin_graphql_api_id' => 'gid://shopify/Product/123',
            'description' => 'A product',
        ];

        $result = $this->removeInternalFields($data);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayHasKey('description', $result);
        $this->assertArrayNotHasKey('__typename', $result);
        $this->assertArrayNotHasKey('admin_graphql_api_id', $result);
    }

    /** @test */
    public function it_removes_custom_fields()
    {
        $data = [
            'id' => '123',
            'title' => 'Product',
            'internal_flag' => true,
            'debug_info' => 'test',
        ];

        $result = $this->removeInternalFields($data, ['internal_flag', 'debug_info']);

        $this->assertArrayHasKey('id', $result);
        $this->assertArrayHasKey('title', $result);
        $this->assertArrayNotHasKey('internal_flag', $result);
        $this->assertArrayNotHasKey('debug_info', $result);
    }

    /** @test */
    public function it_recursively_removes_internal_fields_from_nested_arrays()
    {
        $data = [
            'id' => '123',
            '__typename' => 'Product',
            'variants' => [
                [
                    'id' => 'v1',
                    '__typename' => 'Variant',
                    'title' => 'Small',
                ],
                [
                    'id' => 'v2',
                    '__typename' => 'Variant',
                    'title' => 'Large',
                ],
            ],
        ];

        $result = $this->removeInternalFields($data);

        $this->assertArrayNotHasKey('__typename', $result);
        $this->assertArrayNotHasKey('__typename', $result['variants'][0]);
        $this->assertArrayNotHasKey('__typename', $result['variants'][1]);
        $this->assertEquals('Small', $result['variants'][0]['title']);
        $this->assertEquals('Large', $result['variants'][1]['title']);
    }

    /** @test */
    public function it_handles_last_page_pagination()
    {
        $connection = [
            'edges' => [
                ['node' => ['id' => '1']],
            ],
            'pageInfo' => [
                'endCursor' => null,
                'hasNextPage' => false,
            ],
        ];

        $result = $this->parseConnection($connection);

        $this->assertNull($result['pagination']['next_cursor']);
        $this->assertFalse($result['pagination']['has_more']);
    }
}
