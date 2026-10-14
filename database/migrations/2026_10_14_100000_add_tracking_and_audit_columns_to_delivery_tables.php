<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const TABLES = ['email_reading_deliveries', 'campaign_deliveries'];

    /**
     * Delivery Proof (message_id, smtp_response, send_history) and Open
     * Tracking (tracking_token and the opened_* telemetry) columns, kept
     * symmetrical on both delivery tables. The token is added nullable,
     * backfilled for every existing row, then made unique.
     */
    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->uuid('tracking_token')->nullable();
                $table->string('message_id')->nullable();
                $table->text('smtp_response')->nullable();
                $table->json('send_history')->nullable();
                $table->timestamp('opened_at')->nullable();
                $table->timestamp('last_opened_at')->nullable();
                $table->unsignedInteger('open_count')->default(0);
                $table->string('opened_client_type', 20)->nullable();
                $table->string('opened_ip', 45)->nullable();
                $table->text('opened_user_agent')->nullable();
            });

            // Paged by id: updated rows leave the whereNull() set, so offset
            // paging (chunk/each) would skip every second batch.
            DB::table($table)->whereNull('tracking_token')->chunkById(1000, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    DB::table($table)
                        ->where('id', $row->id)
                        ->update(['tracking_token' => (string) Str::uuid()]);
                }
            });

            Schema::table($table, function (Blueprint $table) {
                $table->unique('tracking_token');
                $table->index('opened_at');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $table) {
                $table->dropUnique(['tracking_token']);
                $table->dropIndex(['opened_at']);
            });

            Schema::table($table, function (Blueprint $table) {
                $table->dropColumn([
                    'tracking_token', 'message_id', 'smtp_response', 'send_history',
                    'opened_at', 'last_opened_at', 'open_count',
                    'opened_client_type', 'opened_ip', 'opened_user_agent',
                ]);
            });
        }
    }
};
