<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('email_reading_products', function (Blueprint $table) {
            $table->boolean('is_automation_enabled')->default(false)->after('is_active');
        });

        // Production-safety backfill (ADR 0020): every currently-active
        // product is already live and auto-emailing today. Without this,
        // deploying this migration would silently stop automation for all
        // of them until someone manually re-toggles each one, with no way
        // to recover orders placed in that gap.
        DB::table('email_reading_products')
            ->where('is_active', true)
            ->update(['is_automation_enabled' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_reading_products', function (Blueprint $table) {
            $table->dropColumn('is_automation_enabled');
        });
    }
};
