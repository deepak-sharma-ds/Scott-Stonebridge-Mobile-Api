<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_customer_id')->index();
            $table->string('customer_email')->index();
            $table->string('fcm_token', 512)->unique();
            $table->string('platform', 16);
            $table->string('device_id')->nullable();
            $table->string('app_version', 32)->nullable();
            $table->boolean('push_enabled')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable()->index();
            $table->timestamps();
            $table->index(['customer_email', 'revoked_at']);
        });

        Schema::create('push_notifications', function (Blueprint $table) {
            $table->id();
            $table->string('source_type', 16)->index();
            $table->string('source_id')->index();
            $table->string('message_id')->nullable();
            $table->string('recipient_email')->index();
            $table->foreignId('device_token_id')
                ->nullable()
                ->constrained('device_tokens')
                ->cascadeOnUpdate()
                ->nullOnDelete();
            $table->string('title');
            $table->text('body');
            $table->json('data')->nullable();
            $table->string('status')->default('pending')->index();
            $table->string('fcm_message_id')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->unique(
                ['source_type', 'source_id', 'recipient_email', 'device_token_id'],
                'push_notifications_dedup_unique'
            );
        });

        Schema::create('klaviyo_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_key')->unique();
            $table->string('flow_id')->index();
            $table->string('recipient_email')->index();
            $table->longText('payload');
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
        });

        Schema::create('klaviyo_campaign_sweeps', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_id')->unique();
            $table->string('campaign_name')->nullable();
            $table->timestamp('send_time')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('events_cursor')->nullable();
            $table->unsignedInteger('recipients_found')->default(0);
            $table->unsignedInteger('pushes_dispatched')->default(0);
            $table->timestamp('swept_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('klaviyo_campaign_sweeps');
        Schema::dropIfExists('klaviyo_webhook_events');
        Schema::dropIfExists('push_notifications');
        Schema::dropIfExists('device_tokens');
    }
};
