<?php

namespace Tests\Unit\Support;

use App\Support\MarkdownMailBody;
use PHPUnit\Framework\TestCase;

class MarkdownMailBodyTest extends TestCase
{
    public function test_converts_markdown_headings_to_html(): void
    {
        $markdown = "## First Question\n\nSome paragraph text.\n\n### From My Heart to Yours\n\nParting blessing.";
        $html = MarkdownMailBody::toHtml($markdown);

        $this->assertStringContainsString('<h2>First Question</h2>', $html);
        $this->assertStringContainsString('<p>Some paragraph text.</p>', $html);
        $this->assertStringContainsString('<h3>From My Heart to Yours</h3>', $html);
        $this->assertStringContainsString('<p>Parting blessing.</p>', $html);
    }

    public function test_converts_markdown_formatting_features(): void
    {
        $markdown = "**Bold message** and *italic words*\n\n> A key blockquote message\n\n- Point 1\n- Point 2";
        $html = MarkdownMailBody::toHtml($markdown);

        $this->assertStringContainsString('<strong>Bold message</strong>', $html);
        $this->assertStringContainsString('<em>italic words</em>', $html);
        $this->assertStringContainsString('<blockquote>', $html);
        $this->assertStringContainsString('<p>A key blockquote message</p>', $html);
        $this->assertStringContainsString('<ul>', $html);
        $this->assertStringContainsString('<li>Point 1</li>', $html);
    }

    public function test_strips_raw_html_tags_and_drops_active_elements_with_content(): void
    {
        $markdown = "## Clean Heading\n\n<script>alert('xss')</script><div class=\"danger\">Text inside div</div>\n\nParagraph with <b>inline raw html</b>.";
        $html = MarkdownMailBody::toHtml($markdown);

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('</script>', $html);
        $this->assertStringNotContainsString("alert('xss')", $html);
        $this->assertStringNotContainsString('<div', $html);
        $this->assertStringNotContainsString('</div>', $html);
        $this->assertStringNotContainsString('<b>', $html);
        $this->assertStringNotContainsString('</b>', $html);
        $this->assertStringContainsString('<h2>Clean Heading</h2>', $html);
        $this->assertStringContainsString('inline raw html', $html);
    }

    public function test_handles_empty_and_whitespace_input(): void
    {
        $this->assertSame('', MarkdownMailBody::toHtml(null));
        $this->assertSame('', MarkdownMailBody::toHtml(''));
        $this->assertSame('', MarkdownMailBody::toHtml("   \n\n  \t  "));
    }

    public function test_strips_unsafe_javascript_links(): void
    {
        $markdown = '[Click me](javascript:alert(1)) and [Good Link](https://example.com)';
        $html = MarkdownMailBody::toHtml($markdown);

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('<a href="https://example.com">Good Link</a>', $html);
    }
}
