<?php

namespace Tests\Unit\DTOs;

use App\DTOs\Collection\CollectionDTO;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * CollectionDTO Unit Tests
 * 
 * Tests validation, factory methods, and serialization for CollectionDTO.
 * 
 * Requirements: 16.5, 16.6, 16.7
 */
class CollectionDTOTest extends TestCase
{
    /**
     * Test that CollectionDTO can be instantiated with valid data.
     */
    public function test_can_create_collection_dto_with_valid_data(): void
    {
        $dto = new CollectionDTO(
            id: 'gid://shopify/Collection/123456789',
            title: 'Summer Collection',
            handle: 'summer-collection',
            description: 'Our best summer products',
            image: [
                'url' => 'https://cdn.shopify.com/image.jpg',
                'alt' => 'Summer collection banner',
            ],
            updatedAt: '2025-01-20T10:30:00Z',
        );

        $this->assertInstanceOf(CollectionDTO::class, $dto);
        $this->assertEquals('gid://shopify/Collection/123456789', $dto->id);
        $this->assertEquals('Summer Collection', $dto->title);
        $this->assertEquals('summer-collection', $dto->handle);
        $this->assertEquals('Our best summer products', $dto->description);
        $this->assertIsArray($dto->image);
        $this->assertEquals('https://cdn.shopify.com/image.jpg', $dto->image['url']);
        $this->assertEquals('Summer collection banner', $dto->image['alt']);
        $this->assertEquals('2025-01-20T10:30:00Z', $dto->updatedAt);
    }

    /**
     * Test that CollectionDTO can be created with minimal required fields.
     */
    public function test_can_create_collection_dto_with_minimal_data(): void
    {
        $dto = new CollectionDTO(
            id: 'gid://shopify/Collection/123',
            title: 'Test Collection',
            handle: 'test-collection',
            description: null,
            image: null,
            updatedAt: null,
        );

        $this->assertInstanceOf(CollectionDTO::class, $dto);
        $this->assertNull($dto->description);
        $this->assertNull($dto->image);
        $this->assertNull($dto->updatedAt);
    }

    /**
     * Test that validation fails when collection ID is empty.
     */
    public function test_validation_fails_when_id_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Collection ID is required');

        new CollectionDTO(
            id: '',
            title: 'Test Collection',
            handle: 'test-collection',
            description: null,
            image: null,
            updatedAt: null,
        );
    }

    /**
     * Test that validation fails when collection title is empty.
     */
    public function test_validation_fails_when_title_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Collection title is required');

        new CollectionDTO(
            id: 'gid://shopify/Collection/123',
            title: '',
            handle: 'test-collection',
            description: null,
            image: null,
            updatedAt: null,
        );
    }

    /**
     * Test that validation fails when collection handle is empty.
     */
    public function test_validation_fails_when_handle_is_empty(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Collection handle is required');

        new CollectionDTO(
            id: 'gid://shopify/Collection/123',
            title: 'Test Collection',
            handle: '',
            description: null,
            image: null,
            updatedAt: null,
        );
    }

    /**
     * Test fromShopifyResponse with complete collection data.
     */
    public function test_from_shopify_response_with_complete_data(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Collection/987654321',
            'title' => 'Winter Collection',
            'handle' => 'winter-collection',
            'description' => 'Cozy winter products',
            'image' => [
                'url' => 'https://cdn.shopify.com/winter.jpg',
                'altText' => 'Winter banner',
            ],
            'updatedAt' => '2025-01-15T08:00:00Z',
        ];

        $dto = CollectionDTO::fromShopifyResponse($shopifyData);

        $this->assertInstanceOf(CollectionDTO::class, $dto);
        $this->assertEquals('gid://shopify/Collection/987654321', $dto->id);
        $this->assertEquals('Winter Collection', $dto->title);
        $this->assertEquals('winter-collection', $dto->handle);
        $this->assertEquals('Cozy winter products', $dto->description);
        $this->assertIsArray($dto->image);
        $this->assertEquals('https://cdn.shopify.com/winter.jpg', $dto->image['url']);
        $this->assertEquals('Winter banner', $dto->image['alt']);
        $this->assertEquals('2025-01-15T08:00:00Z', $dto->updatedAt);
    }

    /**
     * Test fromShopifyResponse with minimal collection data.
     */
    public function test_from_shopify_response_with_minimal_data(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Collection/111',
            'title' => 'Basic Collection',
            'handle' => 'basic-collection',
        ];

        $dto = CollectionDTO::fromShopifyResponse($shopifyData);

        $this->assertInstanceOf(CollectionDTO::class, $dto);
        $this->assertEquals('gid://shopify/Collection/111', $dto->id);
        $this->assertEquals('Basic Collection', $dto->title);
        $this->assertEquals('basic-collection', $dto->handle);
        $this->assertNull($dto->description);
        $this->assertNull($dto->image);
        $this->assertNull($dto->updatedAt);
    }

    /**
     * Test fromShopifyResponse with originalSrc image format (legacy format).
     */
    public function test_from_shopify_response_with_original_src_image(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Collection/222',
            'title' => 'Legacy Collection',
            'handle' => 'legacy-collection',
            'image' => [
                'originalSrc' => 'https://cdn.shopify.com/legacy.jpg',
                'altText' => 'Legacy image',
            ],
        ];

        $dto = CollectionDTO::fromShopifyResponse($shopifyData);

        $this->assertInstanceOf(CollectionDTO::class, $dto);
        $this->assertIsArray($dto->image);
        $this->assertEquals('https://cdn.shopify.com/legacy.jpg', $dto->image['url']);
        $this->assertEquals('Legacy image', $dto->image['alt']);
    }

    /**
     * Test fromShopifyResponse with image but no alt text.
     */
    public function test_from_shopify_response_with_image_without_alt_text(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Collection/333',
            'title' => 'No Alt Collection',
            'handle' => 'no-alt-collection',
            'image' => [
                'url' => 'https://cdn.shopify.com/no-alt.jpg',
            ],
        ];

        $dto = CollectionDTO::fromShopifyResponse($shopifyData);

        $this->assertInstanceOf(CollectionDTO::class, $dto);
        $this->assertIsArray($dto->image);
        $this->assertEquals('https://cdn.shopify.com/no-alt.jpg', $dto->image['url']);
        $this->assertNull($dto->image['alt']);
    }

    /**
     * Test toArray method returns correct structure.
     */
    public function test_to_array_returns_correct_structure(): void
    {
        $dto = new CollectionDTO(
            id: 'gid://shopify/Collection/444',
            title: 'Array Test Collection',
            handle: 'array-test-collection',
            description: 'Testing array conversion',
            image: [
                'url' => 'https://cdn.shopify.com/test.jpg',
                'alt' => 'Test image',
            ],
            updatedAt: '2025-01-20T12:00:00Z',
        );

        $array = $dto->toArray();

        $this->assertIsArray($array);
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('handle', $array);
        $this->assertArrayHasKey('description', $array);
        $this->assertArrayHasKey('image', $array);
        $this->assertArrayHasKey('updatedAt', $array);
        
        $this->assertEquals('gid://shopify/Collection/444', $array['id']);
        $this->assertEquals('Array Test Collection', $array['title']);
        $this->assertEquals('array-test-collection', $array['handle']);
        $this->assertEquals('Testing array conversion', $array['description']);
        $this->assertIsArray($array['image']);
        $this->assertEquals('https://cdn.shopify.com/test.jpg', $array['image']['url']);
        $this->assertEquals('Test image', $array['image']['alt']);
        $this->assertEquals('2025-01-20T12:00:00Z', $array['updatedAt']);
    }

    /**
     * Test toArray with null optional fields.
     */
    public function test_to_array_with_null_optional_fields(): void
    {
        $dto = new CollectionDTO(
            id: 'gid://shopify/Collection/555',
            title: 'Null Fields Collection',
            handle: 'null-fields-collection',
            description: null,
            image: null,
            updatedAt: null,
        );

        $array = $dto->toArray();

        $this->assertIsArray($array);
        $this->assertNull($array['description']);
        $this->assertNull($array['image']);
        $this->assertNull($array['updatedAt']);
    }

    /**
     * Test that properties are readonly.
     */
    public function test_properties_are_readonly(): void
    {
        $dto = new CollectionDTO(
            id: 'gid://shopify/Collection/666',
            title: 'Readonly Test',
            handle: 'readonly-test',
            description: null,
            image: null,
            updatedAt: null,
        );

        $reflection = new \ReflectionClass($dto);
        $properties = $reflection->getProperties(\ReflectionProperty::IS_PUBLIC);

        foreach ($properties as $property) {
            $this->assertTrue(
                $property->isReadOnly(),
                "Property {$property->getName()} should be readonly"
            );
        }
    }

    /**
     * Test edge case: collection with empty image array.
     */
    public function test_from_shopify_response_with_empty_image_array(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Collection/777',
            'title' => 'Empty Image Collection',
            'handle' => 'empty-image-collection',
            'image' => [],
        ];

        $dto = CollectionDTO::fromShopifyResponse($shopifyData);

        $this->assertInstanceOf(CollectionDTO::class, $dto);
        $this->assertIsArray($dto->image);
        $this->assertEquals('', $dto->image['url']);
        $this->assertNull($dto->image['alt']);
    }

    /**
     * Test edge case: collection with special characters in title and handle.
     */
    public function test_collection_with_special_characters(): void
    {
        $dto = new CollectionDTO(
            id: 'gid://shopify/Collection/888',
            title: 'Spring & Summer \'25 Collection!',
            handle: 'spring-summer-25-collection',
            description: 'Special chars: <>&"\'',
            image: null,
            updatedAt: null,
        );

        $this->assertEquals('Spring & Summer \'25 Collection!', $dto->title);
        $this->assertEquals('Special chars: <>&"\'', $dto->description);
    }

    /**
     * Test edge case: very long collection description.
     */
    public function test_collection_with_long_description(): void
    {
        $longDescription = str_repeat('This is a very long description. ', 100);
        
        $dto = new CollectionDTO(
            id: 'gid://shopify/Collection/999',
            title: 'Long Description Collection',
            handle: 'long-description-collection',
            description: $longDescription,
            image: null,
            updatedAt: null,
        );

        $this->assertEquals($longDescription, $dto->description);
        $this->assertGreaterThan(1000, strlen($dto->description));
    }

    /**
     * Test that fromShopifyResponse handles both url and originalSrc preference correctly.
     */
    public function test_from_shopify_response_prefers_url_over_original_src(): void
    {
        $shopifyData = [
            'id' => 'gid://shopify/Collection/1010',
            'title' => 'URL Preference Test',
            'handle' => 'url-preference-test',
            'image' => [
                'url' => 'https://cdn.shopify.com/preferred.jpg',
                'originalSrc' => 'https://cdn.shopify.com/fallback.jpg',
                'altText' => 'Test image',
            ],
        ];

        $dto = CollectionDTO::fromShopifyResponse($shopifyData);

        // Should prefer 'url' over 'originalSrc'
        $this->assertEquals('https://cdn.shopify.com/preferred.jpg', $dto->image['url']);
    }
}
