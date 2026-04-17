<?php

namespace Tests\Unit\Resources;

use App\DTOs\Content\MediaImageDTO;
use App\Http\Resources\Content\MediaImageResource;
use Illuminate\Http\Request;
use Tests\TestCase;

class MediaImageResourceTest extends TestCase
{
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = Request::create('/test', 'POST');
    }

    public function test_it_serializes_media_image_fields(): void
    {
        $mediaImage = new MediaImageDTO(
            id: 'gid://shopify/MediaImage/123',
            url: 'https://cdn.example.com/image.jpg',
            altText: 'Poster',
            width: 1200,
            height: 800,
        );

        $result = (new MediaImageResource($mediaImage))
            ->toResponse($this->request)
            ->getData(true)['data'];

        $this->assertSame('gid://shopify/MediaImage/123', $result['id']);
        $this->assertSame('https://cdn.example.com/image.jpg', $result['url']);
        $this->assertSame('Poster', $result['alt_text']);
        $this->assertSame(1200, $result['width']);
        $this->assertSame(800, $result['height']);
    }
}
