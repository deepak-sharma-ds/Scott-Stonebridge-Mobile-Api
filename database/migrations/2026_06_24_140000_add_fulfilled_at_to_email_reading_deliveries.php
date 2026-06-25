<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-delivery flag: when set, this reading's order line item has been
     * fulfilled in Shopify. Keeps fulfillment idempotent across job retries.
     */
    public function up(): void
    {
        Schema::table('email_reading_deliveries', function (Blueprint $table) {
            $table->dateTime('fulfilled_at')->nullable()->index()->after('expedited_at');
        });
    }

    public function down(): void
    {
        Schema::table('email_reading_deliveries', function (Blueprint $table) {
            $table->dropIndex(['fulfilled_at']);
            $table->dropColumn('fulfilled_at');
        });
    }
};
