<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI\MCP\Mappers;

use App\Services\AI\MCP\Mappers\ProductMapper;
use Tests\TestCase;

class ProductMapperTest extends TestCase
{
    public function test_search_result_maps_products_to_recommendation_dtos(): void
    {
        $result = [
            'products' => [
                [
                    'id' => 'gid://shopify/Product/1',
                    'title' => 'The Fool Tarot Deck',
                    'handle' => 'the-fool-tarot',
                    'vendor' => 'Scott Stonebridge',
                    'currency_code' => 'GBP',
                    'available_for_sale' => true,
                    'variants' => [
                        ['id' => 'gid://shopify/ProductVariant/11', 'price' => '24.99'],
                    ],
                    'featured_image' => ['url' => 'https://cdn.shopify.com/foo.jpg'],
                    'tags' => ['tarot', 'divination'],
                ],
                [
                    // Missing handle → skipped.
                    'id' => 'gid://shopify/Product/2',
                    'title' => 'Bad',
                ],
            ],
        ];

        $dtos = ProductMapper::fromSearchResult($result, 'demo.myshopify.com');

        $this->assertCount(1, $dtos);
        $card = $dtos[0]->toMcpChunk();
        $this->assertSame('gid://shopify/Product/1', $card['id']);
        $this->assertSame('gid://shopify/ProductVariant/11', $card['variant_id']);
        $this->assertSame('The Fool Tarot Deck', $card['title']);
        $this->assertSame('the-fool-tarot', $card['handle']);
        $this->assertSame('https://cdn.shopify.com/foo.jpg', $card['image']);
        $this->assertSame(2499, $card['price_minor_units']);
        $this->assertSame('GBP', $card['currency']);
        $this->assertSame(['tarot', 'divination'], $card['tags']);
    }

    public function test_search_result_tolerates_alternate_envelope_keys(): void
    {
        // Legacy `content[].data` shape — `data` is already an associative
        // array, not a JSON-string under `text`. Bare integer price → minor
        // units (Shopify MCP convention).
        $result = [
            'content' => [[
                'type' => 'application/json',
                'data' => [
                    'products' => [[
                        'id' => 'p1', 'title' => 't', 'handle' => 'h',
                        'variants' => [['id' => 'v1', 'price' => ['amount' => 1000, 'currency' => 'GBP']]],
                    ]],
                ],
            ]],
        ];

        $dtos = ProductMapper::fromSearchResult($result);
        $this->assertCount(1, $dtos);
        $this->assertSame(1000, $dtos[0]->priceMinorUnits);
    }

    public function test_search_result_handles_shopify_content_text_json_string(): void
    {
        // Real Shopify Storefront MCP wraps the payload as
        // `content[0].text = "<JSON string>"`. Map the string back to DTOs.
        $shopifyShape = [
            'content' => [[
                'type' => 'text',
                'text' => json_encode([
                    'products' => [[
                        'id' => 'gid://shopify/Product/1',
                        'title' => 'Year Ahead Insight Bundle',
                        'url' => 'https://scottstonebridge.com/products/year-ahead-insight-bundle',
                        'description' => ['html' => '<p>Spiritual roadmap.</p>'],
                        'price_range' => [
                            'min' => ['amount' => 3111, 'currency' => 'GBP'],
                            'max' => ['amount' => 3111, 'currency' => 'GBP'],
                        ],
                        'variants' => [[
                            'id' => 'gid://shopify/ProductVariant/11',
                            'price' => ['amount' => 3111, 'currency' => 'GBP'],
                        ]],
                    ]],
                ]),
            ]],
        ];

        $dtos = ProductMapper::fromSearchResult($shopifyShape);
        $this->assertCount(1, $dtos);
        $card = $dtos[0]->toMcpChunk();
        $this->assertSame('gid://shopify/Product/1', $card['id']);
        $this->assertSame('year-ahead-insight-bundle', $card['handle']);
        $this->assertSame(3111, $card['price_minor_units']);
        $this->assertSame('GBP', $card['currency']);
    }

    public function test_get_product_builds_full_detail_dto(): void
    {
        $result = [
            'product' => [
                'id' => 'gid://shopify/Product/9',
                'title' => 'Crystal Ball',
                'handle' => 'crystal-ball',
                'descriptionHtml' => '<p>Genuine quartz.</p>',
                'vendor' => 'SSB',
                'tags' => ['divination', 'crystal'],
                'price' => ['amount' => '49.00', 'currency_code' => 'GBP'],
                'images' => [
                    ['url' => 'https://cdn/img1.jpg', 'altText' => 'front'],
                    ['url' => 'https://cdn/img2.jpg'],
                ],
                'variants' => [
                    [
                        'id' => 'v-1', 'title' => 'Small', 'sku' => 'CB-S',
                        'price' => '49.00', 'available' => true,
                        'selected_options' => [['name' => 'Size', 'value' => 'S']],
                    ],
                ],
            ],
        ];

        $dto = ProductMapper::fromGetProduct($result);

        $this->assertNotNull($dto);
        $this->assertSame('Crystal Ball', $dto->title);
        $this->assertSame('GBP', $dto->currency);
        $this->assertSame(4900, $dto->priceMinorUnits);
        $this->assertCount(2, $dto->images);
        $this->assertSame('front', $dto->images[0]['alt']);
        $this->assertCount(1, $dto->variants);
        $this->assertSame(['Size' => 'S'], $dto->variants[0]['options']);
        $this->assertSame(['divination', 'crystal'], $dto->tags);
    }

    public function test_get_product_returns_null_on_missing_required_fields(): void
    {
        $this->assertNull(ProductMapper::fromGetProduct([]));
        $this->assertNull(ProductMapper::fromGetProduct(['product' => ['id' => 'x']]));
    }
}
