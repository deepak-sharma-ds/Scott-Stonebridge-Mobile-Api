<?php

namespace Tests\Unit\Support;

use App\Support\PlainTextMailBody;
use PHPUnit\Framework\TestCase;

class PlainTextMailBodyTest extends TestCase
{
    public function test_it_wraps_blank_line_separated_paragraphs_in_styled_p_tags(): void
    {
        $html = PlainTextMailBody::toHtml("First paragraph.\n\nSecond paragraph.");

        $this->assertSame(
            '<p style="margin:0 0 14px;">First paragraph.</p><p style="margin:0 0 14px;">Second paragraph.</p>',
            $html
        );
    }

    public function test_it_converts_single_newlines_within_a_paragraph_to_line_breaks(): void
    {
        $html = PlainTextMailBody::toHtml("Line one.\nLine two.");

        $this->assertStringContainsString('Line one.<br', $html);
        $this->assertStringContainsString('Line two.', $html);
    }

    public function test_it_escapes_html_in_the_ai_response(): void
    {
        $html = PlainTextMailBody::toHtml('Use <script>alert(1)</script> carefully.');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function test_it_returns_empty_string_for_blank_input(): void
    {
        $this->assertSame('', PlainTextMailBody::toHtml(''));
        $this->assertSame('', PlainTextMailBody::toHtml('   '));
    }
}
