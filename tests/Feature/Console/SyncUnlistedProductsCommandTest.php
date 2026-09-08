<?php

namespace Tests\Feature\Console;

use App\Contracts\Shopify\AdminApiClientInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncUnlistedProductsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_syncs_unlisted_products_and_reports_a_summary(): void
    {
        $this->mock(AdminApiClientInterface::class, function ($mock) {
            $mock->shouldReceive('query')
                ->once()
                ->with('admin/products/list_unlisted', ['first' => 250])
                ->andReturn([
                    'data' => [
                        'products' => [
                            'edges' => [[
                                'node' => [
                                    'id' => 'gid://shopify/Product/111',
                                    'title' => 'Love Reading',
                                    'publishedOnCurrentPublication' => true,
                                    'featuredImage' => ['url' => 'https://cdn.shopify.test/111.jpg', 'altText' => null],
                                    'variants' => [
                                        'edges' => [['node' => ['id' => 'gid://shopify/ProductVariant/11101', 'title' => 'Default Title', 'price' => '19.99', 'availableForSale' => true]]],
                                    ],
                                ],
                            ]],
                            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                        ],
                    ],
                ]);
        });

        $this->artisan('shopify:sync-unlisted-products')
            ->expectsOutputToContain('Synced 1 unlisted products (0 deactivated).')
            ->assertExitCode(0);

        $this->assertDatabaseHas('unlisted_products', ['shopify_product_id' => 111, 'title' => 'Love Reading']);
    }
}
