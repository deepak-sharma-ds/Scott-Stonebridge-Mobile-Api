<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Append-only funnel event log. One row per event emitted by
     * StoreConversionEventJob. NO updated_at column — rows are
     * immutable after insert. Analytics queries read from here +
     * ai_conversations.
     */
    public function up(): void
    {
        Schema::create('conversion_events', function (Blueprint $table): void {
            $table->id();
            $table->string('session_id')->index();
            $table->string('shop_domain')->index();
            $table->string('event_type')->index();
            $table->string('product_id')->nullable();
            $table->string('order_id')->nullable();
            $table->decimal('revenue', 10, 2)->nullable();
            $table->json('metadata_json')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['shop_domain', 'event_type', 'created_at'], 'conv_evt_shop_type_time_idx');
            $table->index(['session_id', 'event_type'], 'conv_evt_session_type_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conversion_events');
    }
};
