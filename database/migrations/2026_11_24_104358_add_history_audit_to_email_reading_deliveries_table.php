<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('email_reading_deliveries', function (Blueprint $table) {
            $table->json('history_included_ids')->nullable();
            $table->unsignedInteger('history_dropped_count')->nullable();
            $table->unsignedInteger('history_tokens')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_reading_deliveries', function (Blueprint $table) {
            $table->dropColumn(['history_included_ids', 'history_dropped_count', 'history_tokens']);
        });
    }
};
