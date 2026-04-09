<?php

namespace Tests\Unit\Resources;

use App\DTOs\Content\PageDTO;
use App\Http\Resources\Content\PageResource;
use Illuminate\Http\Request;
use Tests\TestCase;

class PageResourceTest extends TestCase
{
    private Request $request;

    protected function setUp(): void
    {
        parent::setUp();
        $this->request = Request::create('/test', 'GET');
    }

    public function test_it_serializes_page_seo_and_metafields(): void
    {
        $page = new PageDTO(
            id: 'gid://shopify/Page/1',
            title: 'Event Page',
            handle: 'event-page',
            body: '<p>Body</p>',
            bodySummary: 'Summary',
            seo: [
                'title' => 'SEO Title',
                'description' => 'SEO Description',
            ],
            metafields: [
                [
                    'namespace' => 'custom',
                    'key' => 'event_heading',
                    'value' => 'Live Event',
                ],
            ],
            metadata: [
                'event_heading' => 'Live Event',
            ],
            createdAt: '2026-04-09T10:00:00Z',
            updatedAt: '2026-04-09T11:00:00Z',
        );

        $result = (new PageResource($page))
            ->toResponse($this->request)
            ->getData(true)['data'];

        $this->assertArrayHasKey('seo', $result);
        $this->assertArrayHasKey('metafields', $result);
        $this->assertSame('SEO Title', $result['seo']['title']);
        $this->assertSame('event_heading', $result['metafields'][0]['key']);
        $this->assertSame('Live Event', $result['metadata']['event_heading']);
    }
}
