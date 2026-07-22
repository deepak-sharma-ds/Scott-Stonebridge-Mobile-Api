<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_product_responses', function (Blueprint $table) {
            $table->renameColumn('ai_response', 'body');
        });

        Schema::table('campaign_product_responses', function (Blueprint $table) {
            $table->string('source')->default('manual')->after('campaign_product_id');
        });

        // Every row that existed before this migration was produced by the
        // OpenAI-only flow (ADR 0003) — manual authoring didn't exist yet.
        DB::table('campaign_product_responses')->update(['source' => 'ai']);
    }

    public function down(): void
    {
        Schema::table('campaign_product_responses', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::table('campaign_product_responses', function (Blueprint $table) {
            $table->renameColumn('body', 'ai_response');
        });
    }
};
