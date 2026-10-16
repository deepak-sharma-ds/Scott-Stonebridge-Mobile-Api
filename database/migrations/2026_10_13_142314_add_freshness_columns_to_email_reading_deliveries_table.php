<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Freshness Protocol storage. `theme_summary` is the compact list of cards
     * and themes a reading explored (injected into a returning customer's next
     * prompt); `embedding` is the Reading Embedding (text-embedding-3-small,
     * a JSON array of floats) used for cosine-similarity checks and as a
     * historical corpus.
     */
    public function up(): void
    {
        Schema::table('email_reading_deliveries', function (Blueprint $table) {
            $table->string('theme_summary', 255)->nullable();
            $table->json('embedding')->nullable();
            $table->index('customer_email');
        });
    }

    public function down(): void
    {
        Schema::table('email_reading_deliveries', function (Blueprint $table) {
            $table->dropIndex(['customer_email']);
            $table->dropColumn(['theme_summary', 'embedding']);
        });
    }
};
