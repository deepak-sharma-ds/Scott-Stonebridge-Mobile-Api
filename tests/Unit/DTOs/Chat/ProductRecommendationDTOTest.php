<?php

declare(strict_types=1);

namespace Tests\Unit\DTOs\Chat;

use App\DTOs\Chat\ProductRecommendationDTO;
use App\Http\Resources\AI\ProductRecommendationResource;
use Tests\TestCase;

class ProductRecommendationDTOTest extends TestCase
{
    private function shopifyNode(): array
    {
        return [
            'id' => 'gid://shopify/Product/1',
            'title' => 'The Fool Tarot Deck',
            'handle' => 'the-fool-tarot',
            'vendor' => 'Scott Stonebridge',
            'tags' => ['tarot', 'divination', ''],
            'availableForSale' => true,
            'variants' => ['edges' => [[
                'node' => ['id' => 'gid://shopify/ProductVariant/11', 'price' => ['amount' => '24.99', 'currencyCode' => 'GBP']],
            ]]],
        ];
    }

    public function test_from_shopify_node_extracts_tags_and_drops_blanks(): void
    {
        $dto = ProductRecommendationDTO::fromShopifyNode($this->shopifyNode(), 'demo.myshopify.com');

        $this->assertSame(['tarot', 'divination'], $dto->tags);
    }

    public function test_from_shopify_node_defaults_to_empty_tags_when_absent(): void
    {
        $node = $this->shopifyNode();
        unset($node['tags']);

        $dto = ProductRecommendationDTO::fromShopifyNode($node, 'demo.myshopify.com');

        $this->assertSame([], $dto->tags);
    }

    public function test_mcp_chunk_includes_tags(): void
    {
        $dto = ProductRecommendationDTO::fromShopifyNode($this->shopifyNode(), 'demo.myshopify.com');

        $this->assertSame(['tarot', 'divination'], $dto->toMcpChunk()['tags']);
    }

    public function test_resource_includes_tags(): void
    {
        $dto = ProductRecommendationDTO::fromShopifyNode($this->shopifyNode(), 'demo.myshopify.com');

        $array = (new ProductRecommendationResource($dto))->toArray(request());

        $this->assertSame(['tarot', 'divination'], $array['tags']);
    }
}
