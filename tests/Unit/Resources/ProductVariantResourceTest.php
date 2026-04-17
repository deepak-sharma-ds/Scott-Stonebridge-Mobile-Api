<?php

namespace Tests\Unit\Resources;

use App\DTOs\Product\ProductVariantDTO;
use App\Http\Resources\Product\ProductVariantResource;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * ProductVariantResource Unit Tests
 * 
 * Tests transformation logic from ProductVariantDTO to API response format.
 * Validates field mapping, pricing information, and edge cases.
 */
class ProductVariantResourceTest extends TestCase
{
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = Request::create('/test', 'GET');
    }

    /** @test */
    public function it_transforms_variant_dto_to_array(): void
    {
        // Arrange
        $variantDTO = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/123',
            title: 'Small / Red',
            sku: 'TEST-SKU-001',
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

        // Act
        $resource = new ProductVariantResource($variantDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertIsArray($result);
        $this->assertEquals('gid://shopify/ProductVariant/123', $result['id']);
        $this->assertEquals('Small / Red', $result['title']);
        $this->assertEquals('TEST-SKU-001', $result['sku']);
        $this->assertEquals('29.99', $result['price']);
        $this->assertEquals('GBP', $result['currency_code']);
        $this->assertEquals('39.99', $result['compare_at_price']);
        $this->assertTrue($result['available_for_sale']);
        $this->assertEquals(10, $result['quantity_available']);
        $this->assertEquals('https://example.com/image.jpg', $result['image']);
        $this->assertCount(2, $result['selected_options']);
        $this->assertEquals(0.5, $result['weight']);
        $this->assertEquals('kg', $result['weight_unit']);
    }

    /** @test */
    public function it_handles_variant_with_null_optional_fields(): void
    {
        // Arrange
        $variantDTO = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/456',
            title: 'Default',
            sku: null,
            price: '19.99',
            currencyCode: 'USD',
            compareAtPrice: null,
            availableForSale: false,
            quantityAvailable: null,
            image: null,
            selectedOptions: [],
            weight: null,
            weightUnit: null,
        );

        // Act
        $resource = new ProductVariantResource($variantDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertNull($result['sku']);
        $this->assertNull($result['compare_at_price']);
        $this->assertFalse($result['available_for_sale']);
        $this->assertNull($result['quantity_available']);
        $this->assertNull($result['image']);
        $this->assertEmpty($result['selected_options']);
        $this->assertNull($result['weight']);
        $this->assertNull($result['weight_unit']);
    }

    /** @test */
    public function it_handles_different_currency_codes(): void
    {
        // Arrange - GBP
        $variantGBP = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/1',
            title: 'Variant GBP',
            sku: 'SKU-GBP',
            price: '29.99',
            currencyCode: 'GBP',
            compareAtPrice: null,
            availableForSale: true,
            quantityAvailable: 5,
            image: null,
            selectedOptions: [],
            weight: null,
            weightUnit: null,
        );

        // Arrange - USD
        $variantUSD = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/2',
            title: 'Variant USD',
            sku: 'SKU-USD',
            price: '39.99',
            currencyCode: 'USD',
            compareAtPrice: null,
            availableForSale: true,
            quantityAvailable: 5,
            image: null,
            selectedOptions: [],
            weight: null,
            weightUnit: null,
        );

        // Arrange - EUR
        $variantEUR = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/3',
            title: 'Variant EUR',
            sku: 'SKU-EUR',
            price: '34.99',
            currencyCode: 'EUR',
            compareAtPrice: null,
            availableForSale: true,
            quantityAvailable: 5,
            image: null,
            selectedOptions: [],
            weight: null,
            weightUnit: null,
        );

        // Act
        $resourceGBP = new ProductVariantResource($variantGBP);
        $resourceUSD = new ProductVariantResource($variantUSD);
        $resourceEUR = new ProductVariantResource($variantEUR);

        $resultGBP = $resourceGBP->toArray($this->request);
        $resultUSD = $resourceUSD->toArray($this->request);
        $resultEUR = $resourceEUR->toArray($this->request);

        // Assert
        $this->assertEquals('GBP', $resultGBP['currency_code']);
        $this->assertEquals('USD', $resultUSD['currency_code']);
        $this->assertEquals('EUR', $resultEUR['currency_code']);
    }

    /** @test */
    public function it_preserves_selected_options_structure(): void
    {
        // Arrange
        $variantDTO = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/789',
            title: 'Large / Blue / Cotton',
            sku: 'SKU-789',
            price: '49.99',
            currencyCode: 'GBP',
            compareAtPrice: null,
            availableForSale: true,
            quantityAvailable: 3,
            image: null,
            selectedOptions: [
                ['name' => 'Size', 'value' => 'Large'],
                ['name' => 'Color', 'value' => 'Blue'],
                ['name' => 'Material', 'value' => 'Cotton'],
            ],
            weight: null,
            weightUnit: null,
        );

        // Act
        $resource = new ProductVariantResource($variantDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertCount(3, $result['selected_options']);
        $this->assertEquals('Size', $result['selected_options'][0]['name']);
        $this->assertEquals('Large', $result['selected_options'][0]['value']);
        $this->assertEquals('Color', $result['selected_options'][1]['name']);
        $this->assertEquals('Blue', $result['selected_options'][1]['value']);
        $this->assertEquals('Material', $result['selected_options'][2]['name']);
        $this->assertEquals('Cotton', $result['selected_options'][2]['value']);
    }

    /** @test */
    public function it_handles_compare_at_price_for_sale_items(): void
    {
        // Arrange - with compare at price (on sale)
        $variantOnSale = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/111',
            title: 'On Sale',
            sku: 'SALE-001',
            price: '19.99',
            currencyCode: 'GBP',
            compareAtPrice: '29.99',
            availableForSale: true,
            quantityAvailable: 10,
            image: null,
            selectedOptions: [],
            weight: null,
            weightUnit: null,
        );

        // Arrange - without compare at price (regular price)
        $variantRegular = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/222',
            title: 'Regular Price',
            sku: 'REG-001',
            price: '29.99',
            currencyCode: 'GBP',
            compareAtPrice: null,
            availableForSale: true,
            quantityAvailable: 10,
            image: null,
            selectedOptions: [],
            weight: null,
            weightUnit: null,
        );

        // Act
        $resourceOnSale = new ProductVariantResource($variantOnSale);
        $resourceRegular = new ProductVariantResource($variantRegular);

        $resultOnSale = $resourceOnSale->toArray($this->request);
        $resultRegular = $resourceRegular->toArray($this->request);

        // Assert
        $this->assertEquals('29.99', $resultOnSale['compare_at_price']);
        $this->assertNull($resultRegular['compare_at_price']);
    }

    /** @test */
    public function it_handles_weight_information(): void
    {
        // Arrange - with weight in kg
        $variantKg = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/333',
            title: 'Heavy Item',
            sku: 'HEAVY-001',
            price: '99.99',
            currencyCode: 'GBP',
            compareAtPrice: null,
            availableForSale: true,
            quantityAvailable: 2,
            image: null,
            selectedOptions: [],
            weight: 5.5,
            weightUnit: 'kg',
        );

        // Arrange - with weight in lbs
        $variantLbs = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/444',
            title: 'Light Item',
            sku: 'LIGHT-001',
            price: '19.99',
            currencyCode: 'USD',
            compareAtPrice: null,
            availableForSale: true,
            quantityAvailable: 20,
            image: null,
            selectedOptions: [],
            weight: 2.5,
            weightUnit: 'lb',
        );

        // Act
        $resourceKg = new ProductVariantResource($variantKg);
        $resourceLbs = new ProductVariantResource($variantLbs);

        $resultKg = $resourceKg->toArray($this->request);
        $resultLbs = $resourceLbs->toArray($this->request);

        // Assert
        $this->assertEquals(5.5, $resultKg['weight']);
        $this->assertEquals('kg', $resultKg['weight_unit']);
        $this->assertEquals(2.5, $resultLbs['weight']);
        $this->assertEquals('lb', $resultLbs['weight_unit']);
    }

    /** @test */
    public function it_uses_snake_case_for_field_names(): void
    {
        // Arrange
        $variantDTO = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/555',
            title: 'Test Variant',
            sku: 'TEST-555',
            price: '25.00',
            currencyCode: 'GBP',
            compareAtPrice: '30.00',
            availableForSale: true,
            quantityAvailable: 5,
            image: 'https://example.com/test.jpg',
            selectedOptions: [['name' => 'Size', 'value' => 'M']],
            weight: 1.0,
            weightUnit: 'kg',
        );

        // Act
        $resource = new ProductVariantResource($variantDTO);
        $result = $resource->toArray($this->request);

        // Assert - verify snake_case field names
        $this->assertArrayHasKey('currency_code', $result);
        $this->assertArrayHasKey('compare_at_price', $result);
        $this->assertArrayHasKey('available_for_sale', $result);
        $this->assertArrayHasKey('quantity_available', $result);
        $this->assertArrayHasKey('selected_options', $result);
        $this->assertArrayHasKey('weight_unit', $result);
        
        // Verify camelCase fields are NOT present
        $this->assertArrayNotHasKey('currencyCode', $result);
        $this->assertArrayNotHasKey('compareAtPrice', $result);
        $this->assertArrayNotHasKey('availableForSale', $result);
        $this->assertArrayNotHasKey('quantityAvailable', $result);
        $this->assertArrayNotHasKey('selectedOptions', $result);
        $this->assertArrayNotHasKey('weightUnit', $result);
    }

    /** @test */
    public function it_handles_zero_quantity_available(): void
    {
        // Arrange
        $variantDTO = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/666',
            title: 'Out of Stock',
            sku: 'OOS-001',
            price: '15.00',
            currencyCode: 'GBP',
            compareAtPrice: null,
            availableForSale: false,
            quantityAvailable: 0,
            image: null,
            selectedOptions: [],
            weight: null,
            weightUnit: null,
        );

        // Act
        $resource = new ProductVariantResource($variantDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertEquals(0, $result['quantity_available']);
        $this->assertFalse($result['available_for_sale']);
    }

    /** @test */
    public function it_handles_decimal_prices(): void
    {
        // Arrange
        $variantDTO = new ProductVariantDTO(
            id: 'gid://shopify/ProductVariant/777',
            title: 'Decimal Price',
            sku: 'DEC-001',
            price: '12.50',
            currencyCode: 'GBP',
            compareAtPrice: '15.99',
            availableForSale: true,
            quantityAvailable: 8,
            image: null,
            selectedOptions: [],
            weight: null,
            weightUnit: null,
        );

        // Act
        $resource = new ProductVariantResource($variantDTO);
        $result = $resource->toArray($this->request);

        // Assert
        $this->assertEquals('12.50', $result['price']);
        $this->assertEquals('15.99', $result['compare_at_price']);
    }
}
