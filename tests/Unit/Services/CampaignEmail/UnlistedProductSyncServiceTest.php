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

    private function productNode(int $id, string $title, bool $published = true): array
    {
        return [
            'id' => "gid://shopify/Product/{$id}",
            'title' => $title,
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

        $this->assertSame(['synced' => 1, 'deactivated' => 0], $result);
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

    public function test_a_product_missing_from_the_sync_is_deactivated_not_deleted(): void
    {
        UnlistedProduct::create(['shopify_product_id' => 999, 'title' => 'No Longer Unlisted', 'is_published' => true]);

        $this->mock(AdminApiClientInterface::class, function ($mock) {
            $mock->shouldReceive('query')
                ->once()
                ->with('admin/products/list_unlisted', ['first' => 250])
                ->andReturn([
                    'data' => [
                        'products' => [
                            'edges' => [],
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        ],
                    ],
                ]);
        });

        $result = $this->app->make(UnlistedProductSyncService::class)->sync();

        $this->assertSame(1, $result['deactivated']);
        $this->assertDatabaseHas('unlisted_products', ['shopify_product_id' => 999, 'is_published' => false]);
    }
}
