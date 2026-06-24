<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Allow manually-created (non-Shopify) reading deliveries: such rows have
     * no Shopify order/line-item id. The unique index on shopify_line_item_id
     * permits multiple NULLs in MySQL, so manual rows never collide.
     */
    public function up(): void
    {
        Schema::table('email_reading_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('shopify_order_id')->nullable()->change();
            $table->unsignedBigInteger('shopify_line_item_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('email_reading_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('shopify_order_id')->nullable(false)->change();
            $table->unsignedBigInteger('shopify_line_item_id')->nullable(false)->change();
        });
    }
};
