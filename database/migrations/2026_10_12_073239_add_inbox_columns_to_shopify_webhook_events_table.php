<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Turns the insert-only audit log into a webhook inbox: every event keeps
     * a processing `status` (received -> processing -> processed|ignored|
     * failed), attempts, the outcome `reason` and last `error`, so a failed
     * event can be replayed. Deduplication is scoped per `endpoint`, so two
     * subscriptions to the same Shopify topic can never swallow each other.
     */
    public function up(): void
    {
        Schema::table('shopify_webhook_events', function (Blueprint $table) {
            $table->string('endpoint', 64)->nullable()->after('id');
            $table->string('status', 20)->default('received')->after('hmac_valid');
            $table->unsignedSmallInteger('attempts')->default(0)->after('status');
            $table->string('reason', 120)->nullable()->after('attempts');
            $table->text('error')->nullable()->after('reason');
            $table->timestamp('processed_at')->nullable()->after('received_at');

            $table->dropUnique('shopify_webhook_events_shopify_webhook_id_unique');
            $table->unique(['endpoint', 'shopify_webhook_id'], 'shopify_webhook_events_endpoint_webhook_id_unique');
            $table->index(['status', 'created_at']);
        });

        // Rows written before the inbox existed were handled inline.
        DB::table('shopify_webhook_events')->update(['status' => 'processed']);
    }

    public function down(): void
    {
        Schema::table('shopify_webhook_events', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
            $table->dropUnique('shopify_webhook_events_endpoint_webhook_id_unique');
            $table->unique('shopify_webhook_id');
            $table->dropColumn(['endpoint', 'status', 'attempts', 'reason', 'error', 'processed_at']);
        });
    }
};
