<?php

namespace Tests\Unit\DTOs;

use App\DTOs\Content\PageDTO;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class PageDTOTest extends TestCase
{
    public function test_from_shopify_response_maps_seo_and_metafields(): void
    {
        $dto = PageDTO::fromShopifyResponse([
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
                    'key' => 'event_heading',
                    'value' => 'Live Event',
                ],
                [
                    'namespace' => 'custom',
                    'key' => 'custom.event_date',
                    'value' => '2026-05-01',
                ],
            ],
            'createdAt' => '2026-04-09T10:00:00Z',
            'updatedAt' => '2026-04-09T11:00:00Z',
        ]);

        $this->assertSame('SEO Title', $dto->seo['title']);
        $this->assertCount(2, $dto->metafields);
        $this->assertSame('Live Event', $dto->metadata['event_heading']);
        $this->assertSame('Live Event', $dto->metadata['custom.event_heading']);
        $this->assertSame('2026-05-01', $dto->metadata['custom.event_date']);
    }

    public function test_it_throws_exception_when_required_fields_are_missing(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Title is required');

        new PageDTO(
            id: 'gid://shopify/Page/1',
            title: '',
            handle: 'event-page',
            body: '',
            bodySummary: null,
            seo: null,
            metafields: [],
            metadata: null,
            createdAt: '2026-04-09T10:00:00Z',
            updatedAt: '2026-04-09T11:00:00Z',
        );
    }
}
