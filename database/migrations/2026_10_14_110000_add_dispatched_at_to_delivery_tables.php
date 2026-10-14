<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = ['email_reading_deliveries', 'campaign_deliveries'];

    /**
     * The moment the recipient's mail server accepted the message (Delivery
     * Proof). Distinct from `sent_at`, which the lifecycle clears on every
     * claim and also stamps for manual sends that have no proof, so it cannot
     * date the proof a resend has to archive into `send_history`.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->timestamp('dispatched_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn('dispatched_at');
            });
        }
    }
};
