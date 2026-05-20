<?php

declare(strict_types=1);

namespace App\Services\AI\MCP\Mappers;

final class PolicyMapper
{
    /**
     * @param  array<string, mixed>  $mcpResult  `result` payload from `search_shop_policies_and_faqs`.
     * @return array{answer: string, citations: list<array{title:string,url:string}>}
     */
    public static function fromAnswer(array $mcpResult): array
    {
        $answer = self::extractAnswer($mcpResult);
        $citations = self::extractCitations($mcpResult);

        return [
            'answer' => $answer,
            'citations' => $citations,
        ];
    }

    /**
     * @param  array<string, mixed>  $mcpResult
     */
    private static function extractAnswer(array $mcpResult): string
    {
        foreach (['answer', 'text', 'summary'] as $key) {
            if (isset($mcpResult[$key]) && is_string($mcpResult[$key]) && $mcpResult[$key] !== '') {
                return $mcpResult[$key];
            }
        }

        $content = $mcpResult['content'] ?? null;
        if (is_array($content)) {
            $parts = [];
            foreach ($content as $entry) {
                if (is_array($entry) && isset($entry['text']) && is_string($entry['text']) && $entry['text'] !== '') {
                    $parts[] = $entry['text'];
                }
            }
            if ($parts !== []) {
                return implode("\n\n", $parts);
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $mcpResult
     * @return list<array{title:string,url:string}>
     */
    private static function extractCitations(array $mcpResult): array
    {
        $raw = $mcpResult['citations'] ?? $mcpResult['sources'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $title = (string) ($entry['title'] ?? $entry['name'] ?? $entry['handle'] ?? '');
            $url = (string) ($entry['url'] ?? $entry['link'] ?? '');
            if ($title === '' || $url === '') {
                continue;
            }
            $out[] = ['title' => $title, 'url' => $url];
        }

        return $out;
    }
}
