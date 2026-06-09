<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI\MCP\Mappers;

use App\Services\AI\MCP\Mappers\CartMapper;
use Tests\TestCase;

class CartMapperTest extends TestCase
{
    public function test_maps_cart_with_lines_and_totals(): void
    {
        $result = [
            'cart' => [
                'id' => 'gid://shopify/Cart/abc',
                'checkout_url' => 'https://demo.myshopify.com/cart/checkout?token=xyz',
                'subtotal' => ['amount' => '64.50', 'currency_code' => 'GBP'],
                'lines' => [
                    [
                        'merchandise_id' => 'gid://shopify/ProductVariant/11',
                        'merchandise' => [
                            'product' => ['id' => 'gid://shopify/Product/1', 'title' => 'Tarot'],
                            'image' => ['url' => 'https://cdn/x.jpg'],
                        ],
                        'quantity' => 2,
                        'cost' => ['total' => ['amount' => '49.98', 'currency_code' => 'GBP']],
                    ],
                ],
            ],
        ];

        $dto = CartMapper::fromCart($result);

        $this->assertNotNull($dto);
        $this->assertSame('gid://shopify/Cart/abc', $dto->id);
        $this->assertSame(2, $dto->itemCount);
        $this->assertSame(6450, $dto->subtotalMinorUnits);
        $this->assertSame('GBP', $dto->currency);
        $this->assertSame('https://demo.myshopify.com/cart/checkout?token=xyz', $dto->checkoutUrl);
        $this->assertCount(1, $dto->items);
        $this->assertSame('gid://shopify/ProductVariant/11', $dto->items[0]['variant_id']);
        $this->assertSame('gid://shopify/Product/1', $dto->items[0]['product_id']);
        $this->assertSame('Tarot', $dto->items[0]['title']);
        $this->assertSame(4998, $dto->items[0]['line_price_minor_units']);
    }

    public function test_drops_lines_without_variant_id(): void
    {
        $dto = CartMapper::fromCart([
            'cart' => [
                'id' => 'c1',
                'items' => [
                    ['quantity' => 1, 'title' => 'orphan'],
                    ['variant_id' => 'v1', 'quantity' => 3, 'title' => 'keep'],
                ],
            ],
        ]);

        $this->assertNotNull($dto);
        $this->assertCount(1, $dto->items);
        $this->assertSame('keep', $dto->items[0]['title']);
        $this->assertSame(3, $dto->itemCount);
    }

    public function test_returns_null_for_empty_payload(): void
    {
        $this->assertNull(CartMapper::fromCart(['cart' => []]));
    }
}
