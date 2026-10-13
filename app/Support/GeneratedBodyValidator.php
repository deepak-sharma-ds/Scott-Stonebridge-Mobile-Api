<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Reusable validator for AI-generated and manual response bodies.
 *
 * Enforces the formatting contract:
 *   1. Truncation: finish_reason === 'length'
 *   2. Leftover markdown: unparsed markdown syntax remaining in the HTML
 *   3. Missing closing section: closing heading/section (e.g. 'From My Heart to Yours')
 *   4. Duplicated greeting or sign-off: greetings or signatures already supplied by template
 */
class GeneratedBodyValidator
{
    public const PROBLEM_TRUNCATED = 'Response was truncated by the token limit (finish_reason: length)';

    public const PROBLEM_LEFTOVER_MARKDOWN = 'Response contains leftover unparsed markdown syntax';

    public const PROBLEM_MISSING_CLOSING_SECTION = 'Response is missing closing section ("From My Heart to Yours")';

    public const PROBLEM_DUPLICATED_GREETING = 'Response contains a greeting already supplied by the email template';

    public const PROBLEM_DUPLICATED_SIGNOFF = 'Response contains a sign-off already supplied by the email template';

    /**
     * Validates a generated body (HTML or markdown) and optional finish reason.
     *
     * @param  string  $content  HTML (or raw markdown) response body
     * @param  string|null  $finishReason  OpenAI finish reason (e.g. 'stop', 'length')
     * @param  string|null  $expectedClosingSection  Title of closing section (defaults to 'From My Heart to Yours')
     */
    public static function validate(
        string $content,
        ?string $finishReason = null,
        ?string $expectedClosingSection = 'From My Heart to Yours'
    ): GeneratedBodyValidationResult {
        $problems = [];
        $isTruncated = false;

        if ($finishReason === 'length') {
            $problems[] = self::PROBLEM_TRUNCATED;
            $isTruncated = true;
        }

        $html = self::ensureHtml($content);

        if (self::hasLeftoverMarkdown($html)) {
            $problems[] = self::PROBLEM_LEFTOVER_MARKDOWN;
        }

        if (filled($expectedClosingSection)) {
            if (! self::hasClosingSection($html, (string) $expectedClosingSection)) {
                $problems[] = str_replace(
                    'From My Heart to Yours',
                    (string) $expectedClosingSection,
                    self::PROBLEM_MISSING_CLOSING_SECTION
                );
            }
        }

        if (self::hasDuplicatedGreeting($html)) {
            $problems[] = self::PROBLEM_DUPLICATED_GREETING;
        }

        if (self::hasDuplicatedSignoff($html)) {
            $problems[] = self::PROBLEM_DUPLICATED_SIGNOFF;
        }

        return new GeneratedBodyValidationResult($problems, $isTruncated);
    }

    /**
     * Converts raw markdown to sanitized HTML, or cleans already-structured HTML.
     */
    public static function ensureHtml(string $content): string
    {
        $trimmed = trim($content);

        if ($trimmed === '') {
            return '';
        }

        $hasMarkdownHeadings = (bool) preg_match('/(?:^|\n)\s*#{1,6}\s+/m', $trimmed);
        $isWrappedHtml = (bool) preg_match('/^\s*<(p|h[1-6]|div|blockquote|ul|ol|table)\b/i', $trimmed);

        if ($isWrappedHtml && ! $hasMarkdownHeadings) {
            return SafeHtml::clean($trimmed);
        }

        return MarkdownMailBody::toHtml($trimmed);
    }

    /**
     * Detects unparsed markdown syntax left inside the HTML output.
     */
    public static function hasLeftoverMarkdown(string $html): bool
    {
        $plain = strip_tags($html);

        if (str_contains($plain, '**') || str_contains($plain, '__')) {
            return true;
        }

        if (str_contains($plain, '`') || str_contains($plain, '~~~')) {
            return true;
        }

        if (preg_match('/(?:^|\n|<br\s*\/?>|<p[^>]*>)\s*#{1,6}\s+/i', $html)) {
            return true;
        }

        if (preg_match('/\[[^\]]+\]\([^\)]+\)/', $plain)) {
            return true;
        }

        return false;
    }

    /**
     * Checks if the expected closing section heading/text is present.
     */
    public static function hasClosingSection(string $html, string $expectedSection): bool
    {
        $plain = strip_tags($html);

        return stripos($plain, $expectedSection) !== false;
    }

    /**
     * Detects greeting salutations at the opening of the message.
     */
    public static function hasDuplicatedGreeting(string $html): bool
    {
        $plain = trim(strip_tags($html));

        if ($plain === '') {
            return false;
        }

        $greetingRegex = '/^\s*(?:dear|hello|hi|dearest|greetings)\b/i';

        if (preg_match($greetingRegex, $plain)) {
            return true;
        }

        if (preg_match('/<p[^>]*>(.*?)<\/p>/is', $html, $matches)) {
            $firstParagraph = trim(strip_tags((string) $matches[1]));
            if (preg_match($greetingRegex, $firstParagraph)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detects sign-off formulas or closing signatures that duplicate template footers.
     */
    public static function hasDuplicatedSignoff(string $html): bool
    {
        $plain = trim(strip_tags($html));

        if ($plain === '') {
            return false;
        }

        $signoffPattern = '/(?:^|\n|<br\s*\/?>|<p[^>]*>)\s*(?:warm(?:est)?\s+blessings|with\s+(?:all\s+my\s+)?love(?:\s+and\s+light)?|warm(?:est)?\s+regards|best\s+regards|yours\s+sincerely|sincerely|yours\s+in\s+spirit)(?:\s*[,!.]|\s*<|\s*$|\s*\n)/i';

        if (preg_match($signoffPattern, $html)) {
            return true;
        }

        $signaturePattern = '/(?:^|\n|<br\s*\/?>|<p[^>]*>)\s*scott(?:\s+stonebridge|\s+xx)?\s*(?:<\/p>|\n|<br\s*\/?>|$)/i';

        if (preg_match($signaturePattern, $html)) {
            return true;
        }

        if (preg_match('/(?:warm(?:est)?\s+blessings|with\s+love(?:\s+and\s+light)?)[,\s]+scott/i', $plain)) {
            return true;
        }

        return false;
    }
}
