<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add per-shop chat widget branding fields. Front-end reads these from the
 * `widget` block on /chat/start to render persona name, avatar, brand color
 * and overlay position. All nullable — defaults are applied at the controller
 * layer so an unconfigured shop still renders a sensible widget.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->string('persona_name', 80)->nullable()->after('free_shipping_threshold');
            $table->string('avatar_url', 500)->nullable()->after('persona_name');
            // 7-char hex like #7C3AED — UI applies it as the primary brand swatch.
            $table->string('brand_color', 7)->nullable()->after('avatar_url');
            // "left" | "right" — drives float-positioning of the bubble on storefront.
            $table->string('widget_position', 8)->default('right')->after('brand_color');
        });
    }

    public function down(): void
    {
        Schema::table('shop_settings', function (Blueprint $table): void {
            $table->dropColumn(['persona_name', 'avatar_url', 'brand_color', 'widget_position']);
        });
    }
};
