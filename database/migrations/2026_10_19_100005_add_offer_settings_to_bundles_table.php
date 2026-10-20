<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            // Storefront offer: where the bundle is promoted.
            $table->boolean('offer_on_product_page')->default(true)->after('map_floor');
            $table->boolean('offer_in_cart')->default(true)->after('offer_on_product_page');
            $table->string('offer_heading', 150)->nullable()->after('offer_in_cart');
            // Extra products (besides the components) that show this offer: [{id, title}]
            $table->json('offer_extra_products')->nullable()->after('offer_heading');
            // Products whose zymox_bundle.offers metafield currently references this bundle
            $table->json('offer_synced_product_ids')->nullable()->after('offer_extra_products');
        });
    }

    public function down(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->dropColumn(['offer_on_product_page', 'offer_in_cart', 'offer_heading', 'offer_extra_products', 'offer_synced_product_ids']);
        });
    }
};
