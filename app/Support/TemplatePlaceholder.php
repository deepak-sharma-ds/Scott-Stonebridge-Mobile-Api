<?php

namespace App\Support;

/**
 * Renders admin-authored marketing copy with `{{ $var }}` placeholders
 * substituted from a plain string lookup — not Blade::render(), so stray
 * Blade directives (e.g. `@php`) typed into a rich-text editor can never be
 * compiled/executed. Falls back to `$default` when the template is blank.
 */
final class TemplatePlaceholder
{
    /**
     * @param  array<string,mixed>  $vars
     */
    public static function render(?string $template, array $vars, string $default): string
    {
        $value = $template ?: $default;

        return preg_replace_callback(
            '/\{\{\s*\$(\w+)\s*\}\}/',
            fn (array $match) => array_key_exists($match[1], $vars) ? (string) $vars[$match[1]] : $match[0],
            $value
        );
    }
}
