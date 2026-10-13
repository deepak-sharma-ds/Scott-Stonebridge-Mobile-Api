<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A reading's response is now stored as sanitized rich-text HTML. Convert any
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
        DB::table('email_reading_deliveries')
            ->whereNotNull('ai_response')
            ->where('ai_response', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    $text = trim((string) $row->ai_response);

                    if ($text === '' || preg_match(self::HTML_PATTERN, $text) === 1) {
                        continue;
                    }

                    DB::table('email_reading_deliveries')
                        ->where('id', $row->id)
                        ->update(['ai_response' => $this->toParagraphs($text)]);
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
