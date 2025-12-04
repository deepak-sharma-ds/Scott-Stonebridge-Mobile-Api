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
        Schema::create('api_logs', function (Blueprint $table) {
            $table->id();
            $table->string('actor_type')->nullable()->comment('customer'|'admin'|'system'); // 'customer'|'admin'|'system'
            $table->string('actor_id')->nullable()->comment('Shopify customer id or app user id');   // Shopify customer id or app user id
            $table->string('action')->comment('search, wishlist_add, product_view, login');                 // e.g. search, wishlist_add, product_view, login
            $table->json('meta')->nullable()->comment('{ query: "...", product_id: "...", ip: "..."}');         // { query: "...", product_id: "...", ip: "..."}
            $table->ipAddress('ip')->nullable();
            $table->timestamps();
            $table->index(['action']);
            $table->index(['actor_id']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('api_logs');
    }
};
