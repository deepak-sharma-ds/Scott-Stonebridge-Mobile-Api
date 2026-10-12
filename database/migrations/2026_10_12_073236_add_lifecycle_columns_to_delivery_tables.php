<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['email_reading_deliveries', 'campaign_deliveries'];

    /**
     * The delivery row is the single source of truth for when/whether an
     * email goes out. `claim_token`/`claimed_at` are written by the atomic
     * `-> sending` claim so only one job can own a send; `release_count`
     * caps how often a stuck claim is auto-released; `sent_manually` marks a
     * delivery closed by an admin without an automated send; and
     * `fulfillment_error` makes a failed Shopify fulfilment visible.
     */
    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('claim_token', 36)->nullable();
                $table->timestamp('claimed_at')->nullable();
                $table->unsignedTinyInteger('release_count')->default(0);
                $table->boolean('sent_manually')->default(false);
                $table->text('fulfillment_error')->nullable();
                $table->index(['status', 'scheduled_at']);
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                $table->dropIndex($tableName.'_status_scheduled_at_index');
                $table->dropColumn(['claim_token', 'claimed_at', 'release_count', 'sent_manually', 'fulfillment_error']);
            });
        }
    }
};
