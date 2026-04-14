<?php

namespace Tests\Unit\Services;

use App\Services\Cache\ShopifyCacheStrategy;
use App\Services\Shopify\ContentService;
use Illuminate\Support\Facades\Cache;
use Tests\Mocks\MockShopifyClient;
use Tests\TestCase;

class ContentServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_get_page_by_handle_replaces_media_image_gid_metafields_with_urls(): void
    {
        $mediaImageId = 'gid://shopify/MediaImage/123';
        $mediaImageUrl = 'https://cdn.shopify.com/s/files/1/test/event-left.jpg';

        $client = new MockShopifyClient();
        $client->mockResponse('storefront/content/page_get', [
            'data' => [
                'pageByHandle' => [
                    'id' => 'gid://shopify/Page/1',
                    'title' => 'Event Page',
                    'handle' => 'event-page',
                    'body' => '<p>Body</p>',
                    'bodySummary' => 'Summary',
                    'seo' => [
                        'title' => 'SEO Title',
                        'description' => 'SEO Description',
                    ],
                    'metafields' => [
                        [
                            'namespace' => 'custom',
                            'key' => 'event_left_image',
                            'value' => $mediaImageId,
                        ],
                        [
                            'namespace' => 'custom',
                            'key' => 'event_heading',
                            'value' => 'Live Event',
                        ],
                    ],
                    'createdAt' => '2026-04-09T10:00:00Z',
                    'updatedAt' => '2026-04-09T11:00:00Z',
                ],
            ],
        ]);
        $client->mockResponse('storefront/content/get_media_image', [
            'data' => [
                'node' => [
                    'id' => $mediaImageId,
                    'image' => [
                        'url' => $mediaImageUrl,
                        'altText' => 'Event left image',
                        'width' => 1200,
                        'height' => 800,
                    ],
                ],
            ],
        ]);

        $service = new ContentService($client, new ShopifyCacheStrategy());

        $page = $service->getPageByHandle('event-page');

        $this->assertSame($mediaImageUrl, $page->metafields[0]['value']);
        $this->assertSame('Live Event', $page->metafields[1]['value']);
        $this->assertSame($mediaImageUrl, $page->metadata['event_left_image']);
        $this->assertSame($mediaImageUrl, $page->metadata['custom.event_left_image']);
        $this->assertSame('Live Event', $page->metadata['event_heading']);
    }
}
