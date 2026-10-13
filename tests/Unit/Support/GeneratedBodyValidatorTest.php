<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\GeneratedBodyValidator;
use PHPUnit\Framework\TestCase;

class GeneratedBodyValidatorTest extends TestCase
{
    private function validBody(): string
    {
        return <<<'HTML'
<h2>Your Question Answered</h2>
<p>You have entered a period of transition where balance is key.</p>
<p>A calm mind reveals the path ahead.</p>
<p>Keep your focus on long-term growth.</p>
<h3>From My Heart to Yours</h3>
<p>Walk forward with confidence and know you are protected.</p>
HTML;
    }

    public function test_valid_html_body_passes_all_checks(): void
    {
        $result = GeneratedBodyValidator::validate($this->validBody(), 'stop');

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->isInvalid());
        $this->assertFalse($result->isTruncated());
        $this->assertEmpty($result->problems());
    }

    public function test_valid_markdown_body_passes_all_checks(): void
    {
        $markdown = <<<'MD'
## Your Question Answered

You have entered a period of transition where balance is key.

A calm mind reveals the path ahead.

Keep your focus on long-term growth.

### From My Heart to Yours

Walk forward with confidence and know you are protected.
MD;

        $result = GeneratedBodyValidator::validate($markdown, 'stop');

        $this->assertTrue($result->isValid());
        $this->assertEmpty($result->problems());
    }

    public function test_html_blockquote_is_detected(): void
    {
        $html = <<<'HTML'
<h2>Your Question Answered</h2>
<blockquote>A calm mind reveals the path ahead.</blockquote>
<h3>From My Heart to Yours</h3>
<p>Walk forward with confidence.</p>
HTML;

        $result = GeneratedBodyValidator::validate($html, 'stop');

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_BLOCKQUOTE));
    }

    public function test_markdown_blockquote_is_detected(): void
    {
        $markdown = "## Your Question\n\n> A calm mind reveals the path ahead.\n\n### From My Heart to Yours\n\nWalk forward with confidence.";

        $result = GeneratedBodyValidator::validate($markdown, 'stop');

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_BLOCKQUOTE));
    }

    public function test_html_table_is_detected(): void
    {
        $html = <<<'HTML'
<h2>Your Cards</h2>
<table><tr><td>Past</td><td>Future</td></tr></table>
<h3>From My Heart to Yours</h3>
<p>Walk forward with confidence.</p>
HTML;

        $result = GeneratedBodyValidator::validate($html, 'stop');

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_TABLE));
    }

    public function test_markdown_table_is_detected(): void
    {
        $markdown = "## Your Cards\n\n| Position | Card |\n| --- | --- |\n| Past | The Star |\n\n### From My Heart to Yours\n\nWalk forward with confidence.";

        $result = GeneratedBodyValidator::validate($markdown, 'stop');

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_TABLE));
    }

    public function test_html_horizontal_rule_is_detected(): void
    {
        $html = <<<'HTML'
<h2>Your Reading</h2>
<p>A calm mind reveals the path ahead.</p>
<hr>
<h3>From My Heart to Yours</h3>
<p>Walk forward with confidence.</p>
HTML;

        $result = GeneratedBodyValidator::validate($html, 'stop');

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_DIVIDER));
    }

    public function test_markdown_horizontal_rule_is_detected(): void
    {
        $markdown = "## Your Reading\n\nA calm mind reveals the path ahead.\n\n---\n\n### From My Heart to Yours\n\nWalk forward with confidence.";

        $result = GeneratedBodyValidator::validate($markdown, 'stop');

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_DIVIDER));
    }

    public function test_bullet_and_numbered_lists_are_detected(): void
    {
        $bodies = [
            "## Your Reading\n\n- Trust your instincts\n- Take your time\n\n### From My Heart to Yours\n\nBe well.",
            "## Your Reading\n\n1. Trust your instincts\n2. Take your time\n\n### From My Heart to Yours\n\nBe well.",
            '<h2>Your Reading</h2><ul><li>Trust your instincts</li></ul><h3>From My Heart to Yours</h3><p>Be well.</p>',
            '<h2>Your Reading</h2><ol><li>Trust your instincts</li></ol><h3>From My Heart to Yours</h3><p>Be well.</p>',
        ];

        foreach ($bodies as $body) {
            $result = GeneratedBodyValidator::validate($body, 'stop');

            $this->assertTrue(
                $result->hasProblem(GeneratedBodyValidator::PROBLEM_LIST),
                "Failed to detect list in: {$body}"
            );
        }
    }

    public function test_a_letter_in_flowing_paragraphs_with_soft_headings_has_no_layout_problems(): void
    {
        $markdown = "### Your First Question\n\nThe Star appears here, and it speaks of healing.\n\nTake the next step slowly.\n\n### From My Heart to Yours\n\nWalk forward with confidence.";

        $result = GeneratedBodyValidator::validate($markdown, 'stop');

        $this->assertTrue($result->isValid());
    }

    public function test_truncation_is_detected_when_finish_reason_is_length(): void
    {
        $result = GeneratedBodyValidator::validate($this->validBody(), 'length');

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->isTruncated());
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_TRUNCATED));
    }

    public function test_leftover_unparsed_bold_markdown_is_detected(): void
    {
        $html = <<<'HTML'
<h2>Heading</h2>
<p>This contains **unmatched bold text.</p>
<h3>From My Heart to Yours</h3>
<p>Closing text.</p>
HTML;

        $result = GeneratedBodyValidator::validate($html, 'stop');

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_LEFTOVER_MARKDOWN));
    }

    public function test_leftover_backticks_are_detected(): void
    {
        $html = <<<'HTML'
<h2>Heading</h2>
<p>This contains `code` markdown.</p>
<h3>From My Heart to Yours</h3>
<p>Closing text.</p>
HTML;

        $result = GeneratedBodyValidator::validate($html, 'stop');

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_LEFTOVER_MARKDOWN));
    }

    public function test_unparsed_heading_markdown_in_body_is_detected(): void
    {
        $html = <<<'HTML'
<h2>Heading</h2>
<p>Some paragraph text.<br>### Unparsed Subheading</p>
<h3>From My Heart to Yours</h3>
<p>Closing text.</p>
HTML;

        $result = GeneratedBodyValidator::validate($html, 'stop');

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_LEFTOVER_MARKDOWN));
    }

    public function test_missing_closing_section_is_detected(): void
    {
        $html = <<<'HTML'
<h2>Heading</h2>
<p>Some paragraph text.</p>
<p>Closing without the designated heading.</p>
HTML;

        $result = GeneratedBodyValidator::validate($html, 'stop');

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_MISSING_CLOSING_SECTION));
    }

    public function test_custom_expected_closing_section_can_be_checked(): void
    {
        $html = <<<'HTML'
<h2>Heading</h2>
<p>Some paragraph text.</p>
<h3>Special Parting Words</h3>
<p>Closing text.</p>
HTML;

        $result = GeneratedBodyValidator::validate($html, 'stop', 'Special Parting Words');
        $this->assertTrue($result->isValid());

        $missingResult = GeneratedBodyValidator::validate($html, 'stop', 'Another Custom Heading');
        $this->assertFalse($missingResult->isValid());
        $this->assertStringContainsString('Another Custom Heading', $missingResult->problems()[0]);
    }

    public function test_duplicated_greeting_at_start_is_detected(): void
    {
        $html = <<<'HTML'
<p>Dear Sarah,</p>
<h2>Your Journey</h2>
<p>Content of the reading.</p>
<h3>From My Heart to Yours</h3>
<p>Closing text.</p>
HTML;

        $result = GeneratedBodyValidator::validate($html, 'stop');

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_DUPLICATED_GREETING));
    }

    public function test_duplicated_greeting_variants_are_detected(): void
    {
        $variants = [
            '<p>Hello Jane,</p><p>Content.</p>',
            '<p>Hi there,</p><p>Content.</p>',
            '<p>Dearest friend,</p><p>Content.</p>',
            '<p>Greetings,</p><p>Content.</p>',
        ];

        foreach ($variants as $v) {
            $fullHtml = $v.'<h3>From My Heart to Yours</h3><p>End.</p>';
            $result = GeneratedBodyValidator::validate($fullHtml, 'stop');
            $this->assertTrue(
                $result->hasProblem(GeneratedBodyValidator::PROBLEM_DUPLICATED_GREETING),
                "Failed to detect greeting for: {$v}"
            );
        }
    }

    public function test_greeting_word_boundary_does_not_flag_unrelated_words(): void
    {
        $html = <<<'HTML'
<h2>Your Reading</h2>
<p>High energy surrounds your creative life right now.</p>
<h3>From My Heart to Yours</h3>
<p>Closing text.</p>
HTML;

        $result = GeneratedBodyValidator::validate($html, 'stop');

        $this->assertFalse($result->hasProblem(GeneratedBodyValidator::PROBLEM_DUPLICATED_GREETING));
    }

    public function test_duplicated_signoff_is_detected(): void
    {
        $html = <<<'HTML'
<h2>Your Question Answered</h2>
<p>Some text.</p>
<h3>From My Heart to Yours</h3>
<p>Parting advice.</p>
<p>Warm blessings,<br>Scott Stonebridge</p>
HTML;

        $result = GeneratedBodyValidator::validate($html, 'stop');

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_DUPLICATED_SIGNOFF));
    }

    public function test_duplicated_signoff_variants_are_detected(): void
    {
        $variants = [
            '<p>With love and light,<br>Scott</p>',
            '<p>Warmest blessings,</p>',
            '<p>Scott Stonebridge</p>',
            '<p>Scott xx</p>',
            '<p>Yours in spirit,</p>',
            '<p>Warm regards,</p>',
        ];

        foreach ($variants as $v) {
            $fullHtml = '<h2>Title</h2><p>Body.</p><h3>From My Heart to Yours</h3><p>Text.</p>'.$v;
            $result = GeneratedBodyValidator::validate($fullHtml, 'stop');
            $this->assertTrue(
                $result->hasProblem(GeneratedBodyValidator::PROBLEM_DUPLICATED_SIGNOFF),
                "Failed to detect sign-off for: {$v}"
            );
        }
    }

    public function test_multiple_problems_are_collected(): void
    {
        $html = <<<'HTML'
<p>Dear Jane,</p>
<p>Here is **unclosed bold.</p>
<p>Warm blessings,<br>Scott</p>
HTML;

        $result = GeneratedBodyValidator::validate($html, 'length');

        $this->assertFalse($result->isValid());
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_TRUNCATED));
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_LEFTOVER_MARKDOWN));
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_MISSING_CLOSING_SECTION));
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_DUPLICATED_GREETING));
        $this->assertTrue($result->hasProblem(GeneratedBodyValidator::PROBLEM_DUPLICATED_SIGNOFF));
        $this->assertCount(5, $result->problems());
    }

    public function test_greeting_word_in_later_paragraph_is_not_flagged(): void
    {
        $html = <<<'HTML'
<h2>Your Reading</h2>
<p>A time of great focus begins now.</p>
<p>Dear to your heart is the journey of personal growth.</p>
<h3>From My Heart to Yours</h3>
<p>Walk with courage.</p>
HTML;

        $result = GeneratedBodyValidator::validate($html, 'stop');

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->hasProblem(GeneratedBodyValidator::PROBLEM_DUPLICATED_GREETING));
    }

    public function test_raw_markdown_with_inline_html_is_properly_parsed_and_passes(): void
    {
        $markdown = "## Section\n\nParagraph with <b>inline html</b>.\n\n### From My Heart to Yours\n\nPeace be with you.";

        $result = GeneratedBodyValidator::validate($markdown, 'stop');

        $this->assertTrue($result->isValid());
        $this->assertFalse($result->hasProblem(GeneratedBodyValidator::PROBLEM_LEFTOVER_MARKDOWN));
    }
}
