<?php

use App\Support\ReadingPromptTemplates;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Upgrades the bare-bones prompt templates of the eight reading products to the
 * modality-specific ones (Tarot, mediumship, love, astrology).
 *
 * Rows are matched on the stable Shopify product id, never the slug (rows
 * created by the Reading Catalog Sync have their own slugs). Only
 * `prompt_template` and `max_tokens` are touched:
 *   - a template is replaced only when it is blank or still bare-bones
 *     (shorter than MIN_CUSTOM_LENGTH); a longer template was written by an
 *     admin and is left alone;
 *   - `max_tokens` is only ever raised, never lowered, and only for rows whose
 *     template was upgraded.
 * Missing products are skipped (nothing is created), so re-running is harmless.
 */
return new class extends Migration
{
    /** A template at least this long is treated as admin-written and preserved. */
    private const MIN_CUSTOM_LENGTH = 600;

    public function up(): void
    {
        foreach (ReadingPromptTemplates::all() as $definition) {
            $row = DB::table('email_reading_products')
                ->where('shopify_product_id', $definition['shopify_product_id'])
                ->first(['id', 'prompt_template', 'max_tokens']);

            if ($row === null) {
                continue;
            }

            $current = trim((string) $row->prompt_template);

            if ($current === trim($definition['prompt_template']) || strlen($current) >= self::MIN_CUSTOM_LENGTH) {
                continue;
            }

            DB::table('email_reading_products')
                ->where('id', $row->id)
                ->update([
                    'prompt_template' => $definition['prompt_template'],
                    'max_tokens' => max((int) $row->max_tokens, $definition['max_tokens']),
                    'updated_at' => now(),
                ]);
        }
    }

    /**
     * The previous templates are not restorable (they were free-text rows an
     * admin may have edited), so this is intentionally a no-op.
     */
    public function down(): void {}
};
