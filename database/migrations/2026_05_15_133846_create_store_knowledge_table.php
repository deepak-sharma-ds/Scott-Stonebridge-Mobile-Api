<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-shop knowledge base: pages, policies, blog articles, and merchant
     * FAQs. Each row carries an AI-generated summary capped at ~300 tokens
     * so the prompt builder can inject ≤500 tokens of combined knowledge
     * without exceeding the system-prompt budget.
     *
     * No vector embeddings, no RAG — summaries are picked by intent
     * (config('sales.knowledge.intent_content_map')) and concatenated.
     */
    public function up(): void
    {
        Schema::create('store_knowledge', function (Blueprint $table): void {
            $table->id();
            $table->string('shop_domain')->index();
            $table->enum('content_type', ['page', 'policy', 'blog', 'faq', 'custom']);
            $table->string('title');
            $table->string('handle')->nullable();
            $table->text('summary');
            $table->longText('raw_content');
            $table->timestamp('last_synced_at');
            $table->timestamp('shopify_updated_at')->nullable();
            $table->timestamps();

            // handle is nullable, so the unique covers FAQ rows by title via
            // a manual hash fallback handled in the service (no FAQ collides
            // because handle is auto-generated from the question).
            $table->unique(['shop_domain', 'content_type', 'handle'], 'store_knowledge_shop_type_handle_unique');
            $table->index(['shop_domain', 'content_type'], 'store_knowledge_shop_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_knowledge');
    }
};
