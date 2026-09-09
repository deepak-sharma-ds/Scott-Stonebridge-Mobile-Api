<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Knowledge chunking (see ADR 0009): a document that crosses a length/
 * heading threshold is split into several rows instead of one compressed
 * summary. `document_handle` is the shared, unsuffixed handle every row of
 * one document has in common — set on every row this feature's sync path
 * writes (chunked or not), used to find and reconcile a document's row set
 * on each sync. `chunk_index` is a chunk's 0-based position; null for a row
 * that was never split. Rows from before this feature shipped have both
 * columns null — the reconciliation query also matches on the bare `handle`
 * so those legacy rows still get cleaned up correctly.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('store_knowledge', function (Blueprint $table): void {
            $table->string('document_handle')->nullable()->after('handle');
            $table->unsignedInteger('chunk_index')->nullable()->after('document_handle');
            $table->index(['shop_domain', 'content_type', 'document_handle'], 'store_knowledge_document_idx');
        });
    }

    public function down(): void
    {
        Schema::table('store_knowledge', function (Blueprint $table): void {
            $table->dropIndex('store_knowledge_document_idx');
            $table->dropColumn(['document_handle', 'chunk_index']);
        });
    }
};
