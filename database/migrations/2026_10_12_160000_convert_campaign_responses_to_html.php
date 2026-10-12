<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A campaign response is now stored as sanitized rich-text HTML. Convert any
 * plain-text responses (test data only at the time of writing) to paragraph
 * HTML once. Rows that already look like HTML are skipped, so re-running is
 * harmless. The conversion is inlined so a later change to app helpers can
 * never alter what this migration does.
 */
return new class extends Migration
{
    private const HTML_PATTERN = '/<(p|h[1-6]|ul|ol|li|blockquote|br|hr|div|strong|em|table)[\s>\/]/i';

    public function up(): void
    {
        DB::table('campaign_product_responses')
            ->whereNotNull('body')
            ->where('body', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $text = trim((string) $row->body);

                    if ($text === '' || preg_match(self::HTML_PATTERN, $text) === 1) {
                        continue;
                    }

                    DB::table('campaign_product_responses')
                        ->where('id', $row->id)
                        ->update(['body' => $this->toParagraphs($text)]);
                }
            });
    }

    /**
     * Intentionally empty: the HTML form is a superset of the plain text and
     * is not converted back.
     */
    public function down(): void {}

    private function toParagraphs(string $text): string
    {
        $paragraphs = preg_split('/\n\s*\n/', $text) ?: [$text];

        return implode('', array_map(
            fn (string $paragraph) => '<p>'.nl2br(e(trim($paragraph))).'</p>',
            $paragraphs
        ));
    }
};
