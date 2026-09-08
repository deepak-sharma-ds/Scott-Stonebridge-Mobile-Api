<?php

namespace Tests\Feature\Admin;

use App\Contracts\Shopify\AdminApiClientInterface;
use App\Models\UnlistedProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UnlistedProductControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_the_unlisted_products_index(): void
    {
        UnlistedProduct::create(['shopify_product_id' => 111, 'title' => 'Love Reading', 'is_published' => true]);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('admin.unlisted-products.index'));

        $response->assertOk();
        $response->assertSee('Love Reading');
    }

    public function test_admin_can_update_a_product_email_template(): void
    {
        $product = UnlistedProduct::create(['shopify_product_id' => 111, 'title' => 'Love Reading', 'is_published' => true]);

        $response = $this->actingAs(User::factory()->create())
            ->put(route('admin.unlisted-products.update', $product), [
                'email_content' => 'Updated content.',
                'email_footer' => 'Updated footer.',
            ]);

        $response->assertRedirect(route('admin.unlisted-products.index'));
        $this->assertDatabaseHas('unlisted_products', [
            'id' => $product->id,
            'email_content' => 'Updated content.',
            'email_footer' => 'Updated footer.',
        ]);
    }

    public function test_admin_can_upload_a_header_image_for_a_product(): void
    {
        Storage::fake('public');
        $product = UnlistedProduct::create(['shopify_product_id' => 111, 'title' => 'Love Reading', 'is_published' => true]);

        $this->actingAs(User::factory()->create())
            ->put(route('admin.unlisted-products.update', $product), [
                'header_image' => UploadedFile::fake()->image('header.jpg'),
            ]);

        $product->refresh();
        $this->assertNotNull($product->header_image);
        Storage::disk('public')->assertExists('campaign-header-images/'.$product->header_image);
    }

    public function test_sync_now_button_populates_the_catalog(): void
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

        $response = $this->actingAs(User::factory()->create())
            ->post(route('admin.unlisted-products.sync'));

        $response->assertRedirect(route('admin.unlisted-products.index'));
        $this->assertDatabaseHas('unlisted_products', ['shopify_product_id' => 111, 'title' => 'Love Reading']);
    }
}
