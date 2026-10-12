<?php

namespace App\Support;

/**
 * Turns a stored response body (sanitized rich-text HTML) into the styled
 * HTML placed inside the reading/campaign email. Sanitizing happens again
 * here so nothing unsafe can reach a customer even if a stored value was
 * written by another path. The font family is set on every tag because many
 * email clients do not inherit it from the template, which made links render
 * in a different font from the surrounding text.
 */
final class EmailBodyHtml
{
    private const FONT = 'font-family:Arial,Helvetica,sans-serif;';

    private const HEADING_SPACING = 'margin:28px 0 12px;';

    public static function render(?string $html): string
    {
        return InlineEmailStyles::apply(SafeHtml::clean($html), self::overrides());
    }

    /**
     * @return array<string,string>
     */
    private static function overrides(): array
    {
        $overrides = [];

        foreach (['p', 'li', 'a', 'blockquote', 'td', 'th'] as $tag) {
            $overrides[$tag] = self::FONT;
        }

        foreach (['h1', 'h2', 'h3', 'h4'] as $tag) {
            $overrides[$tag] = self::HEADING_SPACING.self::FONT;
        }

        return $overrides;
    }
}
