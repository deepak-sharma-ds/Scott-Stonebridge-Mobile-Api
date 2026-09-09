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
        Schema::table('klaviyo_campaign_sweeps', function (Blueprint $table) {
            $table->string('title')->nullable()->after('campaign_name');
            $table->text('body')->nullable()->after('title');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('klaviyo_campaign_sweeps', function (Blueprint $table) {
            $table->dropColumn(['title', 'body']);
        });
    }
};
