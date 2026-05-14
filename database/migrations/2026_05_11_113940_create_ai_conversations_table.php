<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Persistent conversation state for the AI chatbot.
     *
     * Shopify product / customer data are NOT stored here — only the session
     * envelope, metadata, and lifecycle timestamps. Live data is resolved
     * dynamically via Storefront / Admin GraphQL on each turn.
     */
    public function up(): void
    {
        Schema::create('ai_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('session_id')->unique();
            $table->string('shopify_customer_id')->nullable()->index();
            $table->string('shop_domain')->index();
            $table->string('page_type')->nullable();
            $table->string('locale', 10)->nullable();
            $table->enum('status', ['active', 'ended', 'escalated'])->default('active')->index();
            $table->json('metadata')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['shop_domain', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_conversations');
    }
};
