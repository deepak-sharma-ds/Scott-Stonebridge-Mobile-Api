<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->string('image_url', 1000)->nullable()->after('description');
            $table->string('shopify_image_url', 1000)->nullable()->after('image_url');
        });
    }

    public function down(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->dropColumn(['image_url', 'shopify_image_url']);
        });
    }
};