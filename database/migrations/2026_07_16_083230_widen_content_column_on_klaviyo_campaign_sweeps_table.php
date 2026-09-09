<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * `content` now stores a campaign's entire rendered HTML template
     * (design + markup, not just an excerpt) so the mobile app can show
     * the marketer's design without rebuilding it. A single email's HTML
     * can exceed TEXT's 64KB cap; widened via raw SQL (no doctrine/dbal
     * dependency needed for a straight MODIFY). MySQL-only: SQLite (tests)
     * has no real column-size limit regardless of declared type, and
     * SQLite's ALTER TABLE doesn't support MODIFY syntax at all.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE klaviyo_campaign_sweeps MODIFY content MEDIUMTEXT NULL');
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE klaviyo_campaign_sweeps MODIFY content TEXT NULL');
        }
    }
};
