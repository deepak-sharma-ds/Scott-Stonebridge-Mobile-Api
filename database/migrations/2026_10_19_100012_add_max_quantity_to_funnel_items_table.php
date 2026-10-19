<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional cap on how many of an offer product a customer can have in the cart from this funnel
 * (whether added as the bundle, increased in the cart, or added singly). Null = no limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funnel_items', function (Blueprint $table) {
            $table->unsignedInteger('max_quantity')->nullable()->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('funnel_items', function (Blueprint $table) {
            $table->dropColumn('max_quantity');
        });
    }
};
