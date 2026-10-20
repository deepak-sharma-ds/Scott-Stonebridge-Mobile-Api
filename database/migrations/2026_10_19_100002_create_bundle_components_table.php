<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundle_components', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->constrained('bundles')->cascadeOnDelete();
            $table->string('shopify_product_id')->index();
            $table->string('shopify_variant_id')->index();
            $table->string('product_title');
            $table->string('variant_title')->nullable();
            $table->string('sku')->nullable()->index();
            $table->unsignedInteger('quantity')->default(1);
            // Snapshot of the component variant price at last sync
            $table->decimal('unit_price', 10, 2)->default(0);
            $table->decimal('map_price', 10, 2)->nullable();
            $table->string('image_url', 1000)->nullable();
            $table->string('product_handle')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['bundle_id', 'shopify_variant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_components');
    }
};
