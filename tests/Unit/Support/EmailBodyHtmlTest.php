<?php

namespace Tests\Unit\Support;

use App\Support\EmailBodyHtml;
use PHPUnit\Framework\TestCase;

class EmailBodyHtmlTest extends TestCase
{
    public function test_it_sanitizes_then_applies_inline_styles_with_a_font_family(): void
    {
        $html = EmailBodyHtml::render('<h2>Card One</h2><p>Body <a href="https://example.com">link</a></p><script>x()</script>');

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringContainsString('<h2 style=', $html);
        $this->assertStringContainsString('font-family:Arial', $html);
        $this->assertMatchesRegularExpression('/<a [^>]*href="https:\/\/example.com"[^>]*font-family:Arial/', $html);
    }

    public function test_headings_get_space_above_them_to_separate_sections(): void
    {
        $html = EmailBodyHtml::render('<h2>Card One</h2>');

        $this->assertStringContainsString('margin:28px 0 12px', $html);
    }

    public function test_a_blockquote_renders_without_a_vertical_stripe(): void
    {
        $html = EmailBodyHtml::render('<blockquote>The answer here is no.</blockquote>');

        $this->assertStringNotContainsString('border-left', $html);
        $this->assertStringContainsString('The answer here is no.', $html);
    }

    public function test_blank_input_gives_an_empty_string(): void
    {
        $this->assertSame('', EmailBodyHtml::render(null));
        $this->assertSame('', EmailBodyHtml::render(''));
    }
}
