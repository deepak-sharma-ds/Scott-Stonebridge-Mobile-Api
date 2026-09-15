<?php

namespace Tests\Unit\Support;

use App\Support\InlineEmailStyles;
use PHPUnit\Framework\TestCase;

class InlineEmailStylesTest extends TestCase
{
    public function test_it_inlines_base_styles_onto_common_tags(): void
    {
        $html = InlineEmailStyles::apply('<h1>Title</h1><p>Body text.</p>');

        $this->assertStringContainsString('<h1 style="margin:0 0 12px;color:#2b1a3d;font-size:24px;font-weight:bold;">Title</h1>', $html);
        $this->assertStringContainsString('<p style="margin:0 0 14px;">Body text.</p>', $html);
    }

    public function test_it_preserves_and_prioritizes_existing_inline_styles(): void
    {
        $html = InlineEmailStyles::apply('<p style="text-align:center;">Centered</p>');

        $this->assertStringContainsString('style="margin:0 0 14px; text-align:center;"', $html);
    }

    public function test_it_returns_empty_string_for_blank_input(): void
    {
        $this->assertSame('', InlineEmailStyles::apply(null));
        $this->assertSame('', InlineEmailStyles::apply('   '));
    }

    public function test_it_preserves_emoji_and_special_characters(): void
    {
        $html = InlineEmailStyles::apply('<p>📱 Please note: I can\'t give guidance — £1 per message.</p>');

        $this->assertStringContainsString('📱', $html);
        $this->assertStringContainsString('£1', $html);
    }

    public function test_it_styles_links_and_lists(): void
    {
        $html = InlineEmailStyles::apply('<ul><li><a href="#">Link</a></li></ul>');

        $this->assertStringContainsString('<ul style="margin:0 0 14px;padding-left:22px;">', $html);
        $this->assertStringContainsString('<a href="#" style="color:#6c3fa1;">', $html);
    }
}
