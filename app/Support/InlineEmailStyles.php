<?php

namespace App\Support;

use DOMDocument;
use DOMXPath;

/**
 * Inlines a fixed set of base styles onto common HTML tags (headings,
 * paragraphs, lists, blockquote, table, links, hr) for admin-authored rich
 * text (CKEditor) email content. Many email clients strip <style> blocks
 * entirely, so structural styling for that content must live on the tags
 * themselves rather than in a stylesheet. Any style attribute already on a
 * tag (e.g. CKEditor's own inline text-align/color) is preserved and wins
 * over these defaults for properties they both set.
 */
final class InlineEmailStyles
{
    private const TAG_STYLES = [
        'h1' => 'margin:0 0 12px;color:#2b1a3d;font-size:24px;font-weight:bold;',
        'h2' => 'margin:0 0 12px;color:#2b1a3d;font-size:20px;font-weight:bold;',
        'h3' => 'margin:0 0 12px;color:#2b1a3d;font-size:17px;font-weight:bold;',
        'h4' => 'margin:0 0 12px;color:#2b1a3d;font-size:15px;font-weight:bold;',
        'p' => 'margin:0 0 14px;',
        'ul' => 'margin:0 0 14px;padding-left:22px;',
        'ol' => 'margin:0 0 14px;padding-left:22px;',
        'li' => 'margin:0 0 6px;',
        'blockquote' => 'margin:0 0 14px;padding:6px 16px;border-left:3px solid #d8cbe8;color:#6b5b7d;font-style:italic;',
        'table' => 'border-collapse:collapse;width:100%;margin:0 0 14px;',
        'td' => 'border:1px solid #e5ddef;padding:8px;',
        'th' => 'border:1px solid #e5ddef;padding:8px;text-align:left;',
        'a' => 'color:#6c3fa1;',
        'hr' => 'border:none;border-top:1px solid #e5ddef;margin:20px 0;',
    ];

    public static function apply(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><html><body>'.$html.'</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        foreach (self::TAG_STYLES as $tag => $style) {
            foreach ($xpath->query('//'.$tag) as $node) {
                $existing = trim($node->getAttribute('style'));
                $node->setAttribute('style', rtrim($style, ';').';'.($existing !== '' ? ' '.$existing : ''));
            }
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        $out = '';
        foreach ($body?->childNodes ?? [] as $child) {
            $out .= $dom->saveHTML($child);
        }

        return $out;
    }
}
