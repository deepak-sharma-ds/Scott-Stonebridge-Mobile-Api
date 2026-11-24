<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('email_reading_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('shopify_customer_id')->nullable()->after('customer_email');
            $table->index('shopify_customer_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_reading_deliveries', function (Blueprint $table) {
            $table->dropIndex(['shopify_customer_id']);
            $table->dropColumn('shopify_customer_id');
        });
    }
};
