<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundles', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain', 150)->index();
            $table->string('title');
            $table->text('description')->nullable();

            // draft = never activated, active = live on storefront, paused = hidden (Shopify DRAFT)
            $table->string('status', 20)->default('draft')->index();
            // pending | synced | failed
            $table->string('sync_status', 20)->default('pending')->index();

            $table->string('shopify_product_id')->nullable()->index();
            $table->string('shopify_variant_id')->nullable()->index();
            $table->string('shopify_handle')->nullable();

            $table->decimal('price', 10, 2);
            $table->decimal('compare_at_price', 10, 2)->nullable();
            $table->decimal('map_floor', 10, 2)->nullable();

            $table->boolean('needs_review')->default(false)->index();
            $table->text('review_reason')->nullable();

            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_sync_error')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundles');
    }
};
