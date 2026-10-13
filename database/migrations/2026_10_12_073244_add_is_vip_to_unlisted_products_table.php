<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Snapshot of Shopify's `vip` product tag, refreshed on each unlisted
     * sync. Webhook payloads carry no product tags, so order-time routing
     * between Email Reading and Campaign flows reads this local flag.
     */
    public function up(): void
    {
        Schema::table('unlisted_products', function (Blueprint $table) {
            $table->boolean('is_vip')->default(false)->index();
        });
    }

    public function down(): void
    {
        Schema::table('unlisted_products', function (Blueprint $table) {
            $table->dropIndex(['is_vip']);
            $table->dropColumn('is_vip');
        });
    }
};
