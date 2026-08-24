<?php

declare(strict_types=1);

namespace App\Services\Sales;

use Symfony\Component\DomCrawler\Crawler;
use Throwable;

/**
 * Splits a long store-knowledge document into retrievable chunks so a
 * single ≤300-token AI summary doesn't have to compress an entire page's
 * detail away. See ADR 0009.
 *
 * A document is chunked when it crosses EITHER threshold: word count or
 * heading count (config('sales.knowledge.chunking.*')). Heading-based
 * sections are preferred since they respect the author's own structure; a
 * fixed-size sliding window (with overlap, so a fact sitting on a boundary
 * still appears whole in at least one chunk) is the fallback for documents
 * with no usable heading structure, and is also applied to cap any single
 * heading-section that is itself still oversized.
 */
final class KnowledgeChunker
{
    /**
     * @return list<string> Chunk texts, in order. Returns [$rawContent]
     *                      unchanged (a single "chunk" = the whole
     *                      document) when neither threshold is crossed.
     */
    public function chunk(string $rawContent): array
    {
        $plainText = $this->plainText($rawContent);
        if ($plainText === '') {
            return [$rawContent];
        }

        $headingSections = $this->splitByHeadings($rawContent);
        $wordCount = $this->wordCount($plainText);

        if ($wordCount < $this->wordThreshold() && count($headingSections) < $this->headingThreshold()) {
            return [$rawContent];
        }

        if (count($headingSections) >= 2) {
            $chunks = [];
            foreach ($headingSections as $section) {
                if ($this->wordCount($this->plainText($section)) > $this->chunkWords() * 2) {
                    array_push($chunks, ...$this->splitFixedSize($section));
                } else {
                    $chunks[] = $section;
                }
            }

            return $chunks;
        }

        // No usable heading structure (plain-text sources like syncUrls()
        // have none at all, and some pages just don't use headings).
        return $this->splitFixedSize($rawContent);
    }

    /**
     * @return list<string>
     */
    private function splitByHeadings(string $html): array
    {
        try {
            $crawler = new Crawler('<div id="__chunk_root">'.$html.'</div>');
            $root = $crawler->filter('#__chunk_root');
        } catch (Throwable) {
            return [];
        }

        if ($root->count() === 0 || $root->children()->count() === 0) {
            return [];
        }

        $sections = [];
        $current = '';
        foreach ($root->children() as $node) {
            $outerHtml = $node->ownerDocument?->saveHTML($node) ?: '';
            if (in_array(strtolower($node->nodeName), ['h2', 'h3'], true) && trim($current) !== '') {
                $sections[] = $current;
                $current = $outerHtml;
            } else {
                $current .= $outerHtml;
            }
        }
        if (trim($current) !== '') {
            $sections[] = $current;
        }

        return array_values(array_filter(
            $sections,
            fn (string $section): bool => $this->plainText($section) !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function splitFixedSize(string $text): array
    {
        $words = preg_split('/\s+/u', $this->plainText($text)) ?: [];
        $words = array_values(array_filter($words, static fn (string $w): bool => $w !== ''));
        if ($words === []) {
            return [$text];
        }

        $chunkWords = $this->chunkWords();
        $overlap = min($this->overlapWords(), $chunkWords - 1);
        $total = count($words);
        $chunks = [];
        $start = 0;

        while ($start < $total) {
            $chunks[] = implode(' ', array_slice($words, $start, $chunkWords));
            if ($start + $chunkWords >= $total) {
                break;
            }
            $start += $chunkWords - $overlap;
        }

        return $chunks === [] ? [$text] : $chunks;
    }

    private function plainText(string $html): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($html)));
    }

    private function wordCount(string $plainText): int
    {
        if ($plainText === '') {
            return 0;
        }

        return count(preg_split('/\s+/u', $plainText) ?: []);
    }

    private function wordThreshold(): int
    {
        return max(1, (int) config('sales.knowledge.chunking.word_threshold', 400));
    }

    private function headingThreshold(): int
    {
        return max(1, (int) config('sales.knowledge.chunking.heading_threshold', 2));
    }

    private function chunkWords(): int
    {
        return max(20, (int) config('sales.knowledge.chunking.chunk_words', 150));
    }

    private function overlapWords(): int
    {
        return max(0, (int) config('sales.knowledge.chunking.overlap_words', 25));
    }
}
