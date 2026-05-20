<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI\MCP\Mappers;

use App\Services\AI\MCP\Mappers\PolicyMapper;
use Tests\TestCase;

class PolicyMapperTest extends TestCase
{
    public function test_reads_direct_answer_and_citations(): void
    {
        $out = PolicyMapper::fromAnswer([
            'answer' => 'Returns accepted within 30 days.',
            'citations' => [
                ['title' => 'Refund Policy', 'url' => 'https://demo.myshopify.com/policies/refund'],
                ['name' => 'FAQ — Returns', 'url' => 'https://demo.myshopify.com/pages/faq#returns'],
                ['title' => 'broken', 'url' => ''], // dropped
            ],
        ]);

        $this->assertSame('Returns accepted within 30 days.', $out['answer']);
        $this->assertCount(2, $out['citations']);
        $this->assertSame('Refund Policy', $out['citations'][0]['title']);
        $this->assertSame('FAQ — Returns', $out['citations'][1]['title']);
    }

    public function test_concatenates_content_text_parts_when_no_direct_answer(): void
    {
        $out = PolicyMapper::fromAnswer([
            'content' => [
                ['type' => 'text', 'text' => 'Free UK shipping over £50.'],
                ['type' => 'text', 'text' => 'International rates vary.'],
            ],
        ]);

        $this->assertSame("Free UK shipping over £50.\n\nInternational rates vary.", $out['answer']);
        $this->assertSame([], $out['citations']);
    }
}
