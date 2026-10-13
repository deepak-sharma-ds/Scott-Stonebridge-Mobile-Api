<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Converts markdown output from OpenAI into sanitized HTML for email response bodies.
 *
 * Drops active content elements (script, style, iframe) along with their text,
 * strips any remaining raw HTML tags so only structured markdown constructs
 * become HTML, and passes the result through SafeHtml to enforce the
 * application-wide tag and attribute allowlist.
 */
final class MarkdownMailBody
{
    private const DROP_ELEMENTS_REGEX = '/<(script|style|iframe|object|embed|applet)[^>]*>.*?<\/\1>/is';

    public static function toHtml(?string $markdown): string
    {
        $markdown = trim((string) $markdown);

        if ($markdown === '') {
            return '';
        }

        // Drop active elements together with their contents before markdown parsing
        $cleanedMarkdown = preg_replace(self::DROP_ELEMENTS_REGEX, '', $markdown) ?? $markdown;

        $html = Str::markdown($cleanedMarkdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);

        return SafeHtml::clean($html);
    }
}
