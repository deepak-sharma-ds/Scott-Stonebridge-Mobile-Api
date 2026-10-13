<?php

namespace Tests\Unit\Services\CampaignEmail;

use App\Contracts\Shopify\AdminApiClientInterface;
use App\Models\UnlistedProduct;
use App\Services\CampaignEmail\UnlistedProductSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnlistedProductSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    private function productNode(int $id, string $title, bool $published = true, array $tags = []): array
    {
        return [
            'id' => "gid://shopify/Product/{$id}",
            'title' => $title,
            'tags' => $tags,
            'publishedOnCurrentPublication' => $published,
            'featuredImage' => ['url' => "https://cdn.shopify.test/{$id}.jpg", 'altText' => null],
            'variants' => [
                'edges' => [
                    ['node' => ['id' => "gid://shopify/ProductVariant/{$id}01", 'title' => 'Default Title', 'price' => '19.99', 'availableForSale' => true]],
                ],
            ],
        ];
    }

    public function test_it_syncs_unlisted_products_into_the_local_table(): void
    {
        $this->mock(AdminApiClientInterface::class, function ($mock) {
            $mock->shouldReceive('query')
                ->once()
                ->with('admin/products/list_unlisted', ['first' => 250])
                ->andReturn([
                    'data' => [
                        'products' => [
                            'edges' => [['node' => $this->productNode(111, 'Love Reading')]],
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        ],
                    ],
                ]);
        });

        $result = $this->app->make(UnlistedProductSyncService::class)->sync();

        $this->assertSame(['synced' => 1, 'created' => 1], $result);
        $this->assertDatabaseHas('unlisted_products', [
            'shopify_product_id' => 111,
            'title' => 'Love Reading',
            'shopify_image_url' => 'https://cdn.shopify.test/111.jpg',
            'is_published' => true,
        ]);
    }

    public function test_it_paginates_across_multiple_pages_using_the_cursor(): void
    {
        $this->mock(AdminApiClientInterface::class, function ($mock) {
            $mock->shouldReceive('query')
                ->once()
                ->with('admin/products/list_unlisted', ['first' => 250])
                ->andReturn([
                    'data' => [
                        'products' => [
                            'edges' => [['node' => $this->productNode(111, 'Page One Product')]],
                            'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-1'],
                        ],
                    ],
                ]);

            $mock->shouldReceive('query')
                ->once()
                ->with('admin/products/list_unlisted', ['first' => 250, 'after' => 'cursor-1'])
                ->andReturn([
                    'data' => [
                        'products' => [
                            'edges' => [['node' => $this->productNode(222, 'Page Two Product')]],
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        ],
                    ],
                ]);
        });

        $result = $this->app->make(UnlistedProductSyncService::class)->sync();

        $this->assertSame(2, $result['synced']);
        $this->assertDatabaseHas('unlisted_products', ['shopify_product_id' => 111]);
        $this->assertDatabaseHas('unlisted_products', ['shopify_product_id' => 222]);
    }

    public function test_resyncing_preserves_admin_authored_template_fields(): void
    {
        UnlistedProduct::create([
            'shopify_product_id' => 111,
            'title' => 'Old Title',
            'header_image' => 'saved-header.jpg',
            'email_content' => 'Saved content.',
            'email_footer' => 'Saved footer.',
            'is_published' => true,
        ]);

        $this->mock(AdminApiClientInterface::class, function ($mock) {
            $mock->shouldReceive('query')
                ->once()
                ->with('admin/products/list_unlisted', ['first' => 250])
                ->andReturn([
                    'data' => [
                        'products' => [
                            'edges' => [['node' => $this->productNode(111, 'New Title')]],
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        ],
                    ],
                ]);
        });

        $this->app->make(UnlistedProductSyncService::class)->sync();

        $this->assertDatabaseHas('unlisted_products', [
            'shopify_product_id' => 111,
            'title' => 'New Title',
            'header_image' => 'saved-header.jpg',
            'email_content' => 'Saved content.',
            'email_footer' => 'Saved footer.',
        ]);
    }

    private function mockProducts(array $nodes): void
    {
        $this->mock(AdminApiClientInterface::class, function ($mock) use ($nodes) {
            $mock->shouldReceive('query')
                ->once()
                ->with('admin/products/list_unlisted', ['first' => 250])
                ->andReturn([
                    'data' => [
                        'products' => [
                            'edges' => array_map(fn ($node) => ['node' => $node], $nodes),
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        ],
                    ],
                ]);
        });
    }

    public function test_a_product_missing_from_the_sync_is_left_untouched(): void
    {
        UnlistedProduct::create(['shopify_product_id' => 999, 'title' => 'No Longer Unlisted', 'is_published' => true]);

        $this->mockProducts([]);

        $this->app->make(UnlistedProductSyncService::class)->sync();

        $this->assertDatabaseHas('unlisted_products', ['shopify_product_id' => 999, 'is_published' => true]);
    }

    public function test_an_empty_or_partial_response_never_unpublishes_the_catalog(): void
    {
        UnlistedProduct::create(['shopify_product_id' => 111, 'title' => 'A', 'is_published' => true]);
        UnlistedProduct::create(['shopify_product_id' => 222, 'title' => 'B', 'is_published' => true]);

        $this->mockProducts([$this->productNode(111, 'A')]);

        $this->app->make(UnlistedProductSyncService::class)->sync();

        $this->assertSame(2, UnlistedProduct::where('is_published', true)->count());
    }

    public function test_resync_never_overwrites_the_admin_controlled_published_flag(): void
    {
        UnlistedProduct::create(['shopify_product_id' => 111, 'title' => 'Old', 'is_published' => false]);

        $this->mockProducts([$this->productNode(111, 'New', published: true)]);

        $this->app->make(UnlistedProductSyncService::class)->sync();

        $this->assertDatabaseHas('unlisted_products', [
            'shopify_product_id' => 111,
            'title' => 'New',
            'is_published' => false,
        ]);
    }

    public function test_a_newly_seen_product_is_created_published(): void
    {
        $this->mockProducts([$this->productNode(111, 'Fresh', published: false)]);

        $this->app->make(UnlistedProductSyncService::class)->sync();

        $this->assertDatabaseHas('unlisted_products', ['shopify_product_id' => 111, 'is_published' => true]);
    }

    public function test_vip_flag_follows_the_shopify_tag_in_both_directions(): void
    {
        $this->mockProducts([$this->productNode(111, 'Vip One', tags: ['Featured', 'VIP'])]);
        $this->app->make(UnlistedProductSyncService::class)->sync();
        $this->assertDatabaseHas('unlisted_products', ['shopify_product_id' => 111, 'is_vip' => true]);

        $this->mockProducts([$this->productNode(111, 'Vip One', tags: ['Featured'])]);
        $this->app->make(UnlistedProductSyncService::class)->sync();
        $this->assertDatabaseHas('unlisted_products', ['shopify_product_id' => 111, 'is_vip' => false]);
    }

    public function test_a_graphql_error_response_throws_instead_of_silently_succeeding(): void
    {
        $this->mock(AdminApiClientInterface::class, function ($mock) {
            $mock->shouldReceive('query')->once()->andReturn(['errors' => [['message' => 'Throttled']]]);
        });

        $this->expectException(\RuntimeException::class);

        $this->app->make(UnlistedProductSyncService::class)->sync();
    }
}
