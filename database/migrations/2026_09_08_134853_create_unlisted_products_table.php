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
        Schema::create('unlisted_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_product_id')->unique();
            $table->string('title');
            $table->string('shopify_image_url')->nullable();
            $table->json('variants')->nullable();
            $table->string('header_image')->nullable();
            $table->text('email_content')->nullable();
            $table->text('email_footer')->nullable();
            $table->boolean('is_published')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('unlisted_products');
    }
};
