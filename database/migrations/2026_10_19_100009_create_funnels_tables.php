<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bundle funnels (UFE-style): a trigger decides where the offer shows, the offer is a set of
 * products with a per-product discount, applied at checkout by Shopify automatic discounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funnels', function (Blueprint $table) {
            $table->id();
            $table->string('shop_domain', 150)->index();
            $table->string('name');
            $table->string('status', 20)->default('inactive')->index(); // active | inactive

            // Trigger: {all: bool, cart_value: {enabled, min}, products: [...], collections: [...], tags: [...]}
            $table->json('trigger');
            // Placements: {product_page: {enabled, style: inpage|popup}, cart_page: {enabled}}
            $table->json('placements');
            $table->json('settings');      // behaviour + scheduling
            $table->json('design');        // colours, fonts, radius, extra CSS
            $table->json('translations');  // widget texts

            // Shopify automatic discounts created for this funnel (one per discount group)
            $table->json('discount_ids')->nullable();
            $table->string('sync_status', 20)->default('pending'); // pending | synced | failed
            $table->text('last_sync_error')->nullable();
            $table->timestamp('last_synced_at')->nullable();

            $table->boolean('needs_review')->default(false);
            $table->text('review_reason')->nullable();

            $table->unsignedBigInteger('bundle_id')->nullable()->index(); // imported from the old bundle
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('funnel_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funnel_id')->constrained('funnels')->cascadeOnDelete();
            $table->string('product_id')->index();
            $table->string('variant_id')->index();
            $table->string('product_title');
            $table->string('variant_title')->nullable();
            $table->string('handle')->nullable();
            $table->string('sku')->nullable();
            $table->string('image_url', 1000)->nullable();
            $table->decimal('price', 10, 2)->default(0);         // snapshot at last save/sync
            $table->unsignedInteger('quantity')->default(1);
            $table->string('discount_type', 20)->default('percentage'); // percentage | fixed | none
            $table->decimal('discount_value', 10, 2)->default(0);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        // Daily counters per funnel (storefront events + orders)
        Schema::create('funnel_stats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funnel_id')->constrained('funnels')->cascadeOnDelete();
            $table->date('date');
            $table->unsignedInteger('impressions')->default(0);
            $table->unsignedInteger('accepts')->default(0);
            $table->unsignedInteger('rejects')->default(0);
            $table->unsignedInteger('checkout_initiates')->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->unique(['funnel_id', 'date']);
        });

        // Orders that contain funnel items (attributed by the _zb_funnel line property)
        Schema::create('funnel_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('funnel_id')->constrained('funnels')->cascadeOnDelete();
            $table->string('shopify_order_id')->index();
            $table->string('order_name')->nullable();
            $table->string('currency', 10)->nullable();
            $table->decimal('order_total', 12, 2)->default(0);
            $table->decimal('attributed_revenue', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->string('placement', 20)->nullable(); // product_page | cart_page
            $table->timestamp('ordered_at')->nullable();
            $table->timestamps();
            $table->unique(['funnel_id', 'shopify_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funnel_orders');
        Schema::dropIfExists('funnel_stats');
        Schema::dropIfExists('funnel_items');
        Schema::dropIfExists('funnels');
    }
};
