<?php

namespace Tests\Unit\DTOs;

use App\DTOs\Content\MediaImageDTO;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MediaImageDTOTest extends TestCase
{
    public function test_from_shopify_response_maps_media_image_data(): void
    {
        $dto = MediaImageDTO::fromShopifyResponse([
            'id' => 'gid://shopify/MediaImage/123',
            'image' => [
                'url' => 'https://cdn.example.com/image.jpg',
                'altText' => 'Poster',
                'width' => 1200,
                'height' => 800,
            ],
        ]);

        $this->assertSame('gid://shopify/MediaImage/123', $dto->id);
        $this->assertSame('https://cdn.example.com/image.jpg', $dto->url);
        $this->assertSame('Poster', $dto->altText);
        $this->assertSame(1200, $dto->width);
        $this->assertSame(800, $dto->height);
    }

    public function test_it_throws_exception_when_url_is_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Media image URL is required');

        new MediaImageDTO(
            id: 'gid://shopify/MediaImage/123',
            url: '',
            altText: null,
            width: null,
            height: null,
        );
    }
}
