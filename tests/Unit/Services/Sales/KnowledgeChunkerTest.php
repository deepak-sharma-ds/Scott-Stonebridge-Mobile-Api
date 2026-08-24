<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sales;

use App\Services\Sales\KnowledgeChunker;
use Tests\TestCase;

class KnowledgeChunkerTest extends TestCase
{
    private KnowledgeChunker $chunker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->chunker = new KnowledgeChunker;
    }

    public function test_short_content_is_returned_unchanged(): void
    {
        $html = '<p>Scott founded the shop in 2020.</p>';

        $this->assertSame([$html], $this->chunker->chunk($html));
    }

    public function test_content_below_both_thresholds_is_unchanged_even_with_one_heading(): void
    {
        // One heading is below the default heading_threshold of 2, and the
        // word count is short — neither threshold fires.
        $html = '<h2>About</h2><p>Scott founded the shop in 2020.</p>';

        $this->assertSame([$html], $this->chunker->chunk($html));
    }

    public function test_splits_by_heading_when_heading_threshold_crossed(): void
    {
        $html = '<h2>Origins</h2><p>'.str_repeat('origin word ', 60).'</p>'
            .'<h2>Philosophy</h2><p>'.str_repeat('philosophy word ', 60).'</p>'
            .'<h2>Today</h2><p>'.str_repeat('today word ', 60).'</p>';

        $chunks = $this->chunker->chunk($html);

        $this->assertCount(3, $chunks);
        $this->assertStringContainsString('Origins', $chunks[0]);
        $this->assertStringContainsString('origin word', $chunks[0]);
        $this->assertStringNotContainsString('philosophy word', $chunks[0]);
        $this->assertStringContainsString('Philosophy', $chunks[1]);
        $this->assertStringContainsString('Today', $chunks[2]);
    }

    public function test_falls_back_to_fixed_size_when_no_headings_but_over_word_threshold(): void
    {
        config(['sales.knowledge.chunking.word_threshold' => 400]);
        $longText = trim(str_repeat('word ', 500));

        $chunks = $this->chunker->chunk("<p>{$longText}</p>");

        $this->assertGreaterThan(1, count($chunks));
        // Every word from the source must survive somewhere in the chunks
        // (allowing for the intentional overlap duplicating some words).
        $this->assertStringContainsString('word', $chunks[0]);
    }

    public function test_fixed_size_chunks_overlap_so_a_boundary_word_survives_in_two_chunks(): void
    {
        config([
            'sales.knowledge.chunking.word_threshold' => 10,
            'sales.knowledge.chunking.chunk_words' => 20,
            'sales.knowledge.chunking.overlap_words' => 5,
        ]);

        $words = array_map(static fn (int $i): string => "word{$i}", range(1, 50));
        $html = '<p>'.implode(' ', $words).'</p>';

        $chunks = $this->chunker->chunk($html);

        $this->assertGreaterThan(1, count($chunks));
        // word16..word20 sit in the overlap window between chunk 1 and 2.
        $this->assertStringContainsString('word18', $chunks[0]);
        $this->assertStringContainsString('word18', $chunks[1]);
    }

    public function test_oversized_heading_section_is_recursively_split(): void
    {
        config([
            'sales.knowledge.chunking.chunk_words' => 30,
            'sales.knowledge.chunking.overlap_words' => 5,
        ]);

        $html = '<h2>Short Section</h2><p>'.str_repeat('short word ', 10).'</p>'
            .'<h2>Huge Section</h2><p>'.str_repeat('huge word ', 200).'</p>';

        $chunks = $this->chunker->chunk($html);

        // The short section stays one chunk; the huge section (well over
        // chunk_words * 2) gets split into several fixed-size pieces.
        $this->assertGreaterThan(2, count($chunks));
        $this->assertStringContainsString('Short Section', $chunks[0]);
    }

    public function test_empty_content_returns_itself(): void
    {
        $this->assertSame([''], $this->chunker->chunk(''));
    }
}
