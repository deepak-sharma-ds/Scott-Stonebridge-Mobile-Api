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
        $unwrapped = McpEnvelope::unwrap($mcpResult);
        $answer = self::extractAnswer($unwrapped);
        $citations = self::extractCitations($unwrapped);

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
        foreach (['answer', 'text', 'summary', '_text'] as $key) {
            if (isset($mcpResult[$key]) && is_string($mcpResult[$key]) && $mcpResult[$key] !== '') {
                return $mcpResult[$key];
            }
        }

        // Shopify `search_shop_policies_and_faqs` returns a list of
        // `{question, answer}` entries — concatenate the answers.
        $list = $mcpResult['_list'] ?? null;
        if (is_array($list) && $list !== []) {
            $parts = [];
            foreach ($list as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $q = is_string($row['question'] ?? null) ? trim($row['question']) : '';
                $a = is_string($row['answer'] ?? null) ? trim($row['answer']) : '';
                if ($a === '') {
                    continue;
                }
                $parts[] = $q !== '' ? "**{$q}**\n{$a}" : $a;
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

        // Synthesise lightweight citations from Q&A list entries so the
        // frontend can still render chips even though Shopify's policy MCP
        // does not return source URLs.
        if ($out === [] && isset($mcpResult['_list']) && is_array($mcpResult['_list'])) {
            foreach ($mcpResult['_list'] as $row) {
                if (! is_array($row)) {
                    continue;
                }
                $title = is_string($row['question'] ?? null) ? trim($row['question']) : '';
                if ($title === '') {
                    continue;
                }
                $out[] = ['title' => $title, 'url' => ''];
            }
        }

        return $out;
    }
}
