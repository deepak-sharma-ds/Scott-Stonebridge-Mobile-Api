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
        Schema::create('audio_download_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('audio_id');
            $table->string('customer_id')->nullable()->comment('Shopify customer id or app user id'); // Shopify customer id or app user id
            $table->string('source')->nullable()->comment("'app'|'shopify'|'admin'"); // 'app'|'shopify'|'admin'
            $table->timestamp('downloaded_at')->useCurrent();
            $table->string('ip')->nullable();
            $table->json('meta')->nullable();
            $table->index(['audio_id']);
            $table->index(['customer_id']);
            $table->index('downloaded_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audio_download_logs');
    }
};
