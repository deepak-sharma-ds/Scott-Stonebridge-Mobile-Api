<?php

namespace Tests\Unit\Support;

use App\Support\SafeHtml;
use PHPUnit\Framework\TestCase;

class SafeHtmlTest extends TestCase
{
    public function test_it_keeps_allowed_formatting_tags(): void
    {
        $html = '<h2>Card One</h2><p>Hello <strong>you</strong> and <em>me</em>.</p><blockquote>Key message</blockquote><ul><li>One</li></ul><hr>';

        $this->assertSame($html, SafeHtml::clean($html));
    }

    public function test_it_drops_scripts_and_other_active_elements_with_their_content(): void
    {
        $clean = SafeHtml::clean('<p>Safe</p><script>alert(1)</script><iframe src="https://evil.example"></iframe><style>p{}</style><object data="x"></object>');

        $this->assertSame('<p>Safe</p>', $clean);
    }

    public function test_it_unwraps_unknown_tags_but_keeps_their_text(): void
    {
        $clean = SafeHtml::clean('<custom-tag>Inner <b>bold</b></custom-tag>');

        $this->assertStringNotContainsString('custom-tag', $clean);
        $this->assertStringContainsString('Inner', $clean);
        $this->assertStringContainsString('<b>bold</b>', $clean);
    }

    public function test_it_strips_event_handler_and_unknown_attributes(): void
    {
        $clean = SafeHtml::clean('<p onclick="x()" class="big" id="a" data-x="1">Text</p>');

        $this->assertSame('<p>Text</p>', $clean);
    }

    public function test_it_rejects_unsafe_link_schemes_but_keeps_safe_ones(): void
    {
        $this->assertSame('<a>bad</a>', SafeHtml::clean('<a href="javascript:alert(1)">bad</a>'));
        $this->assertSame('<a>bad</a>', SafeHtml::clean("<a href=\"java\tscript:alert(1)\">bad</a>"));
        $this->assertSame('<a>bad</a>', SafeHtml::clean('<a href="data:text/html;base64,AAAA">bad</a>'));
        $this->assertSame('<a href="https://example.com/x">ok</a>', SafeHtml::clean('<a href="https://example.com/x">ok</a>'));
        $this->assertSame('<a href="mailto:a@example.com">ok</a>', SafeHtml::clean('<a href="mailto:a@example.com">ok</a>'));
        $this->assertSame('<a href="tel:+44123">ok</a>', SafeHtml::clean('<a href="tel:+44123">ok</a>'));
    }

    public function test_it_rejects_protocol_relative_urls(): void
    {
        $this->assertSame('<a>x</a>', SafeHtml::clean('<a href="//evil.example/x">x</a>'));
        $this->assertSame('<img alt="A">', SafeHtml::clean('<img src="//evil.example/p.gif" alt="A">'));
        $this->assertSame('<a href="/local">x</a>', SafeHtml::clean('<a href="/local">x</a>'));
    }

    public function test_it_removes_processing_instructions_and_cdata(): void
    {
        $clean = SafeHtml::clean('<p>Hi</p><?php echo 1; ?><![CDATA[ secret ]]>');

        $this->assertStringNotContainsString('<?', $clean);
        $this->assertStringNotContainsString('CDATA', $clean);
        $this->assertStringContainsString('<p>Hi</p>', $clean);
    }

    public function test_it_only_allows_http_and_relative_image_sources(): void
    {
        $this->assertSame('<img src="https://example.com/a.png" alt="A">', SafeHtml::clean('<img src="https://example.com/a.png" alt="A" onerror="x()">'));
        $this->assertSame('<img alt="A">', SafeHtml::clean('<img src="javascript:alert(1)" alt="A">'));
    }

    public function test_it_keeps_only_safe_style_properties(): void
    {
        $clean = SafeHtml::clean('<p style="text-align:center;color:#ff0000;position:absolute;background:url(javascript:x)">Hi</p>');

        $this->assertSame('<p style="text-align:center;color:#ff0000">Hi</p>', $clean);
    }

    public function test_it_drops_a_style_attribute_when_nothing_in_it_is_safe(): void
    {
        $this->assertSame('<p>Hi</p>', SafeHtml::clean('<p style="position:fixed;top:0">Hi</p>'));
    }

    public function test_it_removes_comments(): void
    {
        $this->assertSame('<p>Hi</p>', SafeHtml::clean('<p>Hi</p><!-- secret -->'));
    }

    public function test_it_leaves_plain_text_alone_and_returns_empty_for_blank_input(): void
    {
        $this->assertSame('Just text.', SafeHtml::clean('Just text.'));
        $this->assertSame('', SafeHtml::clean(null));
        $this->assertSame('', SafeHtml::clean("  \n "));
    }

    public function test_is_blank_treats_editor_leftovers_as_empty(): void
    {
        $this->assertTrue(SafeHtml::isBlank(null));
        $this->assertTrue(SafeHtml::isBlank(''));
        $this->assertTrue(SafeHtml::isBlank('<p>&nbsp;</p>'));
        $this->assertTrue(SafeHtml::isBlank("<p>\u{00A0}</p><p><br></p>"));
        $this->assertFalse(SafeHtml::isBlank('<p>0</p>'));
        $this->assertFalse(SafeHtml::isBlank('<p>Hello</p>'));
        $this->assertFalse(SafeHtml::isBlank('<img src="https://example.com/a.png">'));
        $this->assertFalse(SafeHtml::isBlank('<hr>'));
    }

    public function test_it_preserves_unicode_and_entities(): void
    {
        $clean = SafeHtml::clean('<p>Café — “quoted” &amp; £5 💜</p>');

        $this->assertStringContainsString('Café', $clean);
        $this->assertStringContainsString('£5', $clean);
        $this->assertStringContainsString('💜', $clean);
        $this->assertStringContainsString('&amp;', $clean);
    }

    public function test_table_markup_from_the_editor_survives(): void
    {
        $clean = SafeHtml::clean('<figure class="table"><table><tbody><tr><td colspan="2">Cell</td></tr></tbody></table></figure>');

        $this->assertStringContainsString('<table><tbody><tr><td colspan="2">Cell</td></tr></tbody></table>', $clean);
        $this->assertStringNotContainsString('figure', $clean);
    }
}
