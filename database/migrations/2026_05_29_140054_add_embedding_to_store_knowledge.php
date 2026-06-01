<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store an OpenAI embedding per row so the retrieval picker can rank
 * by cosine similarity to the user's question. Embedding is stored as
 * JSON (1536 floats for text-embedding-3-small) — no native vector
 * index is required at this row count (~260 per shop); cosine is
 * computed in PHP against the candidate set from FULLTEXT + intent
 * filtering.
 *
 * Nullable so existing rows keep working until backfilled via
 * `php artisan knowledge:embed {shop}`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_knowledge', function (Blueprint $table): void {
            $table->json('embedding')->nullable()->after('summary');
            $table->string('embedding_model', 64)->nullable()->after('embedding');
            $table->timestamp('embedded_at')->nullable()->after('embedding_model');
        });
    }

    public function down(): void
    {
        Schema::table('store_knowledge', function (Blueprint $table): void {
            $table->dropColumn(['embedding', 'embedding_model', 'embedded_at']);
        });
    }
};
