<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
        // Non-MySQL drivers (the SQLite test database) have no MODIFY syntax;
        // Laravel 11's native change() covers them. MySQL keeps the raw SQL.
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('email_reading_products', function (Blueprint $table) {
                $table->json('questions_schema')->nullable()->change();
                $table->longText('prompt_template')->nullable()->change();
                $table->string('email_subject')->nullable()->change();
            });

            return;
        }

        DB::statement('ALTER TABLE email_reading_products MODIFY questions_schema JSON NULL');
        DB::statement('ALTER TABLE email_reading_products MODIFY prompt_template LONGTEXT NULL');
        DB::statement('ALTER TABLE email_reading_products MODIFY email_subject VARCHAR(255) NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            Schema::table('email_reading_products', function (Blueprint $table) {
                $table->json('questions_schema')->nullable(false)->change();
                $table->longText('prompt_template')->nullable(false)->change();
                $table->string('email_subject')->nullable(false)->change();
            });

            return;
        }

        DB::statement('ALTER TABLE email_reading_products MODIFY questions_schema JSON NOT NULL');
        DB::statement('ALTER TABLE email_reading_products MODIFY prompt_template LONGTEXT NOT NULL');
        DB::statement('ALTER TABLE email_reading_products MODIFY email_subject VARCHAR(255) NOT NULL');
    }
};
