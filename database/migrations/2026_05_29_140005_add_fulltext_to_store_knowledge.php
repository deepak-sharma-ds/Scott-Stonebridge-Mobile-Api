<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Add a MySQL FULLTEXT index over (title, summary) so the knowledge
 * retrieval picker can rank rows by relevance to the user's question
 * (MATCH AGAINST in NATURAL LANGUAGE MODE) instead of only filtering
 * by intent + ordering by updated_at.
 *
 * Skipped on SQLite (test env) — FULLTEXT is not supported there and
 * the service falls back to LIKE-style scoring in PHP when MATCH is
 * unavailable, so unit tests keep working without the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE store_knowledge ADD FULLTEXT INDEX store_knowledge_search_ft (title, summary)');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement('ALTER TABLE store_knowledge DROP INDEX store_knowledge_search_ft');
    }
};
