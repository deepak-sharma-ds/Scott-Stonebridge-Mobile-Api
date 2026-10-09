<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Raw SQL (not Blueprint::change(), which needs doctrine/dbal — not an
     * installed dependency) to make these three columns nullable. The
     * Reading Catalog Sync creates a row with only Shopify-sourced metadata
     * (name, shopify_product_id); questions_schema/prompt_template/
     * email_subject are filled in later by the admin via the Edit form,
     * which is where their required-ness is still enforced
     * (EmailReadingProductRequest) — this migration only relaxes the DB
     * constraint that was blocking that intermediate, not-yet-configured
     * state from being saved at all.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE email_reading_products MODIFY questions_schema JSON NULL');
        DB::statement('ALTER TABLE email_reading_products MODIFY prompt_template LONGTEXT NULL');
        DB::statement('ALTER TABLE email_reading_products MODIFY email_subject VARCHAR(255) NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE email_reading_products MODIFY questions_schema JSON NOT NULL');
        DB::statement('ALTER TABLE email_reading_products MODIFY prompt_template LONGTEXT NOT NULL');
        DB::statement('ALTER TABLE email_reading_products MODIFY email_subject VARCHAR(255) NOT NULL');
    }
};
