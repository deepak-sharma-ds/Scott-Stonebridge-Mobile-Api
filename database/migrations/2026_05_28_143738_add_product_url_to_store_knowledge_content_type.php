<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Widen the `store_knowledge.content_type` enum so the per-shop knowledge
 * table can absorb two new sources:
 *
 *   - 'product' : per-product summary derived from Shopify Storefront
 *                 GraphQL (descriptionHtml + vendor/type/tags/variants).
 *                 Lets the bot answer fuzzy catalogue questions without
 *                 hitting search_catalog every turn.
 *   - 'url'     : arbitrary live-website content scraped from
 *                 https://{shop}/sitemap.xml or explicit URLs. Captures
 *                 theme-rendered landing pages and app-injected content
 *                 that the Admin API does not expose.
 *
 * Existing rows are unaffected — this is a pure widening of the enum.
 */
return new class extends Migration
{
    public function up(): void
    {
        // SQLite (test env) does not support `ALTER TABLE ... MODIFY COLUMN`
        // and its column types are dynamic — enum constraints don't exist
        // there. MySQL/MariaDB need the explicit enum widening so existing
        // rows stay valid while new types are accepted.
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(
            "ALTER TABLE store_knowledge MODIFY COLUMN content_type ENUM('page','policy','blog','faq','custom','product','url') NOT NULL"
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        // Reversal requires removing rows of the new types first; otherwise
        // the narrower enum would reject them. Acceptable dataloss on a
        // down migration — knowledge rows are derived data and can be
        // re-synced via the artisan commands.
        DB::statement("DELETE FROM store_knowledge WHERE content_type IN ('product','url')");
        DB::statement(
            "ALTER TABLE store_knowledge MODIFY COLUMN content_type ENUM('page','policy','blog','faq','custom') NOT NULL"
        );
    }
};
