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
        Schema::table('audio', function (Blueprint $table) {
            $table->string('hls_path')->nullable()->after('file_path');
            $table->boolean('is_hls_ready')->default(false)->after('hls_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audio', function (Blueprint $table) {
            $table->dropColumn(['hls_path', 'is_hls_ready']);
        });
    }
};
