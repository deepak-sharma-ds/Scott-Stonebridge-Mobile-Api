<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundle_sync_logs', function (Blueprint $table) {
            $table->foreignId('funnel_id')->nullable()->after('bundle_id')->constrained('funnels')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bundle_sync_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('funnel_id');
        });
    }
};
