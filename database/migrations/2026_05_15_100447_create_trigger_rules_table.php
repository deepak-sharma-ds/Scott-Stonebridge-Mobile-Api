<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proactive trigger rules per shop + page combination. Drives the
     * GET /api/v1/ai/triggers endpoint that the storefront calls on page load
     * to decide whether the chat widget should open itself with a contextual
     * message (exit intent, time on page, scroll depth, cart abandonment).
     *
     * No business logic — pure rule storage. Evaluation, token interpolation,
     * and dedupe-per-session live in ProactiveTriggerService.
     */
    public function up(): void
    {
        Schema::create('trigger_rules', function (Blueprint $table): void {
            $table->id();
            $table->string('shop_domain')->index();
            $table->enum('page_type', ['home', 'product', 'cart', 'collection', 'all']);
            $table->enum('trigger_type', ['exit_intent', 'time_on_page', 'scroll_depth', 'cart_abandonment']);

            // Seconds for time_on_page, percent (0-100) for scroll_depth,
            // unused for exit_intent / cart_abandonment.
            $table->unsignedInteger('trigger_value')->nullable();

            // Token-interpolated by ProactiveTriggerService::buildProactiveMessage
            // (supports {product_title}, {cart_total}, etc.).
            $table->text('message_template');

            $table->boolean('is_active')->default(true);

            // Lower value fires first when multiple rules match.
            $table->unsignedTinyInteger('priority')->default(10);

            $table->timestamps();

            $table->index(['shop_domain', 'page_type', 'is_active'], 'trig_shop_page_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trigger_rules');
    }
};
