<?php

namespace App\Support;

/**
 * Converts a plain-text AI response (blank-line-separated paragraphs, as
 * returned by OpenAI) into safe, styled HTML paragraphs for email — instead
 * of relying on `white-space: pre-line`, which depends on the email client
 * honoring CSS at all (several strip <style> blocks entirely, and the
 * property is easy to lose on the surrounding element by accident).
 */
final class PlainTextMailBody
{
    public static function toHtml(string $text): string
    {
        $text = trim($text);

        if ($text === '') {
            return '';
        }

        $paragraphs = preg_split('/\n\s*\n/', $text) ?: [$text];

        $html = implode('', array_map(
            fn (string $paragraph) => '<p>'.nl2br(e(trim($paragraph))).'</p>',
            $paragraphs
        ));

        return InlineEmailStyles::apply($html);
    }
}
