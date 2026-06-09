<?php

declare(strict_types=1);

namespace App\Services\AI\MCP\Mappers;

/**
 * Shopify MCP wraps tool results as:
 *
 *   { "result": { "content": [ { "type": "text", "text": "<JSON string>" } ] } }
 *
 * `McpClient::callTool()` already strips the top-level `result` wrapper, so we
 * land here with `{ content: [...] }`. This helper unwraps the inner JSON
 * string (or pass-through unchanged on legacy / non-content envelopes).
 */
final class McpEnvelope
{
    /**
     * @param  array<string, mixed>  $mcpResult
     * @return array<string, mixed>
     */
    public static function unwrap(array $mcpResult): array
    {
        $content = $mcpResult['content'] ?? null;
        if (! is_array($content)) {
            return $mcpResult;
        }

        $merged = [];
        foreach ($content as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            // The `text` field carries either inline plain text (policy
            // answers) or a JSON string (products / cart / order). Try to
            // decode; on failure keep the literal under a `_text` accumulator
            // so PolicyMapper can still surface it.
            $text = $entry['text'] ?? null;
            if (is_string($text)) {
                $decoded = json_decode($text, true);
                if (is_array($decoded)) {
                    if (array_is_list($decoded)) {
                        // List-shape payloads (e.g. policy Q&A `[{question, answer}, ...]`)
                        // land under `_list` so domain-specific mappers can iterate.
                        $merged['_list'] = isset($merged['_list']) && is_array($merged['_list'])
                            ? array_merge($merged['_list'], $decoded)
                            : $decoded;
                    } else {
                        $merged = array_replace_recursive($merged, $decoded);
                    }

                    continue;
                }

                $merged['_text'] = isset($merged['_text']) ? $merged['_text']."\n\n".$text : $text;

                continue;
            }

            // Older / structured `data` payloads.
            $data = $entry['data'] ?? null;
            if (is_array($data)) {
                $merged = array_replace_recursive($merged, $data);
            }
        }

        // Preserve any top-level keys alongside the unwrapped content (e.g.
        // a tool may set both `content[]` and `products` at the top).
        $passthrough = $mcpResult;
        unset($passthrough['content']);

        return array_replace_recursive($merged, $passthrough);
    }
}
