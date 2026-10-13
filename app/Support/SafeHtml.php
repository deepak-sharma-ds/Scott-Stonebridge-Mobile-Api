<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

/**
 * Allowlist sanitizer for admin-edited and AI-generated rich-text bodies
 * (the CKEditor output stored on readings and campaign responses).
 *
 * Active elements are removed together with their content, unknown elements
 * are unwrapped (their text is kept), every attribute outside a small
 * per-tag allowlist is dropped, link/image URLs are restricted to safe
 * schemes, and inline styles are reduced to a few harmless properties.
 */
final class SafeHtml
{
    /** @var array<string,list<string>> allowed tag => allowed attributes */
    private const ALLOWED = [
        'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [],
        'p' => [], 'br' => [], 'hr' => [], 'span' => [],
        'strong' => [], 'b' => [], 'em' => [], 'i' => [], 'u' => [], 's' => [],
        'blockquote' => [], 'ul' => [], 'ol' => [], 'li' => [],
        'a' => ['href', 'title'],
        'img' => ['src', 'alt', 'width', 'height'],
        'table' => [], 'thead' => [], 'tbody' => [], 'tfoot' => [], 'tr' => [],
        'td' => ['colspan', 'rowspan'], 'th' => ['colspan', 'rowspan'],
    ];

    /** Elements removed together with everything inside them. */
    private const DROP_WITH_CONTENT = [
        'script', 'style', 'iframe', 'frame', 'frameset', 'object', 'embed', 'applet',
        'noscript', 'template', 'svg', 'math', 'form', 'input', 'button', 'textarea',
        'select', 'option', 'head', 'title', 'meta', 'link', 'base', 'audio', 'video',
    ];

    private const SAFE_STYLE_PROPERTIES = [
        'text-align', 'color', 'background-color', 'font-size', 'font-weight',
        'font-style', 'text-decoration',
    ];

    public static function clean(?string $html): string
    {
        $html = trim((string) $html);

        if ($html === '') {
            return '';
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><html><body>'.$html.'</body></html>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $body = $dom->getElementsByTagName('body')->item(0);

        if (! $body) {
            return '';
        }

        self::sanitizeChildren($body);

        $out = '';
        foreach ($body->childNodes as $child) {
            $out .= $dom->saveHTML($child);
        }

        return trim($out);
    }

    /**
     * True when the HTML shows nothing to a reader: no text (non-breaking
     * spaces count as empty) and no image or rule. The editor leaves
     * `<p>&nbsp;</p>` behind when its content is cleared.
     */
    public static function isBlank(?string $html): bool
    {
        $html = (string) $html;

        if (preg_match('/<(img|hr)[\s>\/]/i', $html) === 1) {
            return false;
        }

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(str_replace("\u{00A0}", ' ', $text)) === '';
    }

    private static function sanitizeChildren(DOMNode $parent): void
    {
        foreach (iterator_to_array($parent->childNodes) as $child) {
            if ($child instanceof DOMText) {
                continue;
            }

            if (! $child instanceof DOMElement) {
                $parent->removeChild($child);

                continue;
            }

            $tag = strtolower($child->tagName);

            if (in_array($tag, self::DROP_WITH_CONTENT, true)) {
                $parent->removeChild($child);

                continue;
            }

            self::sanitizeChildren($child);

            if (! array_key_exists($tag, self::ALLOWED)) {
                self::unwrap($parent, $child);

                continue;
            }

            self::sanitizeAttributes($child, self::ALLOWED[$tag]);
        }
    }

    private static function unwrap(DOMNode $parent, DOMElement $element): void
    {
        while ($element->firstChild) {
            $parent->insertBefore($element->firstChild, $element);
        }

        $parent->removeChild($element);
    }

    /**
     * @param  list<string>  $allowed
     */
    private static function sanitizeAttributes(DOMElement $element, array $allowed): void
    {
        foreach (iterator_to_array($element->attributes) as $attribute) {
            $name = strtolower($attribute->name);

            if ($name === 'style') {
                continue;
            }

            if (! in_array($name, $allowed, true)) {
                $element->removeAttribute($attribute->name);

                continue;
            }

            if (in_array($name, ['href', 'src'], true) && ! self::isSafeUrl($attribute->value, $name === 'href')) {
                $element->removeAttribute($attribute->name);
            }
        }

        if ($element->hasAttribute('style')) {
            $style = self::cleanStyle($element->getAttribute('style'));

            if ($style === '') {
                $element->removeAttribute('style');
            } else {
                $element->setAttribute('style', $style);
            }
        }
    }

    private static function isSafeUrl(string $url, bool $isLink): bool
    {
        $url = preg_replace('/[\x00-\x20\x7F]+/', '', $url) ?? '';

        if ($url === '') {
            return false;
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $url, $match) !== 1) {
            if (str_starts_with($url, '//')) {
                return false;
            }

            return str_starts_with($url, '/') || ($isLink && str_starts_with($url, '#'));
        }

        $allowedSchemes = $isLink ? ['http', 'https', 'mailto', 'tel'] : ['http', 'https'];

        return in_array(strtolower($match[1]), $allowedSchemes, true);
    }

    private static function cleanStyle(string $style): string
    {
        $kept = [];

        foreach (explode(';', $style) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }

            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);

            if (! in_array($property, self::SAFE_STYLE_PROPERTIES, true)) {
                continue;
            }

            if (preg_match('/^[#a-z0-9\s.,%()\-]+$/i', $value) !== 1 || stripos($value, 'url(') !== false || stripos($value, 'expression') !== false) {
                continue;
            }

            $kept[] = $property.':'.$value;
        }

        return implode(';', $kept);
    }
}
