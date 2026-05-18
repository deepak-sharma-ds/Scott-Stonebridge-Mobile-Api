<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-shop settings driving Phase 2 features:
     *   - default_locale_override: forces a locale regardless of payload/headers.
     *   - allowed_locales_json: restricts the resolver output to a whitelist.
     *   - welcome_messages_json: keyed by locale; seeds the initial assistant
     *     message at session start.
     *   - free_shipping_threshold: per-shop override read by UpsellService
     *     (config('sales.upsell.default_free_shipping_threshold') is the fallback).
     */
    public function up(): void
    {
        Schema::create('shop_settings', function (Blueprint $table): void {
            $table->id();
            $table->string('shop_domain')->unique();
            $table->string('default_locale_override', 10)->nullable();
            $table->json('allowed_locales_json')->nullable();
            $table->json('welcome_messages_json')->nullable();
            $table->decimal('free_shipping_threshold', 10, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shop_settings');
    }
};
