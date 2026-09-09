<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaign_products', function (Blueprint $table) {
            $table->unsignedBigInteger('shopify_variant_id')->nullable()->after('shopify_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('campaign_products', function (Blueprint $table) {
            $table->dropColumn('shopify_variant_id');
        });
    }
};
