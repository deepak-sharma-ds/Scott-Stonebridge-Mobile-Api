<?php

namespace Tests\Unit\DTOs;

use App\DTOs\Product\ProductVariantDTO;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ProductVariantDTOTest extends TestCase
{
    /**
     * Test that ProductVariantDTO can be instantiated with valid data.
     */
    public function test_can_create_product_variant_dto_with_valid_data(): void
    {
        $dto = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/123',
            title: 'Small / Red',
            sku: 'SKU-123',
            price: '29.99',
            currencyCode: 'GBP',
            compareAtPrice: '39.99',
            availableForSale: true,
            quantityAvailable: 10,
            image: 'https://example.com/image.jpg',
            selectedOptions: [
                ['name' => 'Size', 'value' => 'Small'],
                ['name' => 'Color', 'value' => 'Red'],
            ],
            weight: 0.5,
            weightUnit: 'kg',
        );

        $this->assertEquals('gid://shopify/ProductVariant/123', $dto->id);
        $this->assertEquals('Small / Red', $dto->title);
        $this->assertEquals('SKU-123', $dto->sku);
        $this->assertEquals('29.99', $dto->price);
        $this->assertEquals('GBP', $dto->currencyCode);
        $this->assertEquals('39.99', $dto->compareAtPrice);
        $this->assertTrue($dto->availableForSale);
        $this->assertEquals(10, $dto->quantityAvailable);
        $this->assertEquals('https://example.com/image.jpg', $dto->image);
        $this->assertCount(2, $dto->selectedOptions);
        $this->assertEquals(0.5, $dto->weight);
        $this->assertEquals('kg', $dto->weightUnit);
    }

    /**
     * Test that ProductVariantDTO throws exception when ID is empty.
     */
    public function test_throws_exception_when_id_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Variant ID is required');

        new ProductVariantDTO(
            id: '',
            title: 'Small / Red',
            sku: null,
            price: '29.99',
            currencyCode: 'GBP',
            compareAtPrice: null,
            availableForSale: true,
            quantityAvailable: null,
            image: null,
            selectedOptions: [],
            weight: null,
            weightUnit: null,
        );
    }

    /**
     * Test that ProductVariantDTO throws exception when title is empty.
     */
    public function test_throws_exception_when_title_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Variant title is required');

        new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/123',
            title: '',
            sku: null,
            price: '29.99',
            currencyCode: 'GBP',
            compareAtPrice: null,
            availableForSale: true,
            quantityAvailable: null,
            image: null,
            selectedOptions: [],
            weight: null,
            weightUnit: null,
        );
    }

    /**
     * Test that ProductVariantDTO throws exception when price is empty.
     */
    public function test_throws_exception_when_price_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Variant price is required');

        new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/123',
            title: 'Small / Red',
            sku: null,
            price: '',
            currencyCode: 'GBP',
            compareAtPrice: null,
            availableForSale: true,
            quantityAvailable: null,
            image: null,
            selectedOptions: [],
            weight: null,
            weightUnit: null,
        );
    }

    /**
     * Test that ProductVariantDTO throws exception when currency code is empty.
     */
    public function test_throws_exception_when_currency_code_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Currency code is required');

        new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/123',
            title: 'Small / Red',
            sku: null,
            price: '29.99',
            currencyCode: '',
            compareAtPrice: null,
            availableForSale: true,
            quantityAvailable: null,
            image: null,
            selectedOptions: [],
            weight: null,
            weightUnit: null,
        );
    }

    /**
     * Test fromShopifyResponse() creates DTO from Shopify data.
     */
    public function test_from_shopify_response_creates_dto(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/ProductVariant/123',
            'title' => 'Small / Red',
            'sku' => 'SKU-123',
            'price' => [
                'amount' => '29.99',
                'currencyCode' => 'GBP',
            ],
            'compareAtPrice' => [
                'amount' => '39.99',
            ],
            'availableForSale' => true,
            'quantityAvailable' => 10,
            'image' => [
                'url' => 'https://example.com/image.jpg',
            ],
            'selectedOptions' => [
                ['name' => 'Size', 'value' => 'Small'],
                ['name' => 'Color', 'value' => 'Red'],
            ],
            'weight' => 0.5,
            'weightUnit' => 'kg',
        ];

        $dto = ProductVariantDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('gid://shopify/ProductVariant/123', $dto->id);
        $this->assertEquals('Small / Red', $dto->title);
        $this->assertEquals('SKU-123', $dto->sku);
        $this->assertEquals('29.99', $dto->price);
        $this->assertEquals('GBP', $dto->currencyCode);
        $this->assertEquals('39.99', $dto->compareAtPrice);
        $this->assertTrue($dto->availableForSale);
        $this->assertEquals(10, $dto->quantityAvailable);
        $this->assertEquals('https://example.com/image.jpg', $dto->image);
        $this->assertCount(2, $dto->selectedOptions);
    }

    /**
     * Test fromShopifyResponse() handles priceV2 format.
     */
    public function test_from_shopify_response_handles_price_v2_format(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/ProductVariant/123',
            'title' => 'Default',
            'priceV2' => [
                'amount' => '19.99',
                'currencyCode' => 'USD',
            ],
            'compareAtPriceV2' => [
                'amount' => '29.99',
            ],
            'availableForSale' => true,
            'selectedOptions' => [],
        ];

        $dto = ProductVariantDTO::fromShopifyResponse($shopifyData);

        $this->assertEquals('19.99', $dto->price);
        $this->assertEquals('USD', $dto->currencyCode);
        $this->assertEquals('29.99', $dto->compareAtPrice);
    }

    /**
     * Test fromShopifyResponse() handles missing optional fields.
     */
    public function test_from_shopify_response_handles_missing_optional_fields(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/ProductVariant/123',
            'title' => 'Default',
            'price' => [
                'amount' => '29.99',
                'currencyCode' => 'GBP',
            ],
        ];

        $dto = ProductVariantDTO::fromShopifyResponse($shopifyData);

        $this->assertNull($dto->sku);
        $this->assertNull($dto->compareAtPrice);
        $this->assertFalse($dto->availableForSale);
        $this->assertNull($dto->quantityAvailable);
        $this->assertNull($dto->image);
        $this->assertEmpty($dto->selectedOptions);
        $this->assertNull($dto->weight);
        $this->assertNull($dto->weightUnit);
    }

    /**
     * Test toArray() converts DTO to array.
     */
    public function test_to_array_converts_dto_to_array(): void
    {
        $dto = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/123',
            title: 'Small / Red',
            sku: 'SKU-123',
            price: '29.99',
            currencyCode: 'GBP',
            compareAtPrice: '39.99',
            availableForSale: true,
            quantityAvailable: 10,
            image: 'https://example.com/image.jpg',
            selectedOptions: [
                ['name' => 'Size', 'value' => 'Small'],
            ],
            weight: 0.5,
            weightUnit: 'kg',
        );

        $array = $dto->toArray();

        $this->assertIsArray($array);
        $this->assertEquals('gid://shopify/ProductVariant/123', $array['id']);
        $this->assertEquals('Small / Red', $array['title']);
        $this->assertEquals('SKU-123', $array['sku']);
        $this->assertEquals('29.99', $array['price']);
        $this->assertEquals('GBP', $array['currencyCode']);
        $this->assertEquals('39.99', $array['compareAtPrice']);
        $this->assertTrue($array['availableForSale']);
        $this->assertEquals(10, $array['quantityAvailable']);
        $this->assertIsArray($array['selectedOptions']);
    }

    /**
     * Test toArray() handles null values correctly.
     */
    public function test_to_array_handles_null_values(): void
    {
        $dto = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/123',
            title: 'Default',
            sku: null,
            price: '29.99',
            currencyCode: 'GBP',
            compareAtPrice: null,
            availableForSale: true,
            quantityAvailable: null,
            image: null,
            selectedOptions: [],
            weight: null,
            weightUnit: null,
        );

        $array = $dto->toArray();

        $this->assertNull($array['sku']);
        $this->assertNull($array['compareAtPrice']);
        $this->assertNull($array['quantityAvailable']);
        $this->assertNull($array['image']);
        $this->assertNull($array['weight']);
        $this->assertNull($array['weightUnit']);
    }
}
