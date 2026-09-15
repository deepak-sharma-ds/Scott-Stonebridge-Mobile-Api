<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
     {
        if (Schema::hasTable('sync_events')) {
            return;
        }

        Schema::create('sync_events', function (Blueprint $table) {
            $table->id();

            $table->string('email', 191);
            $table->string('channel', 20);
            $table->string('state', 20);
            $table->string('source', 20);

            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index(['email', 'channel', 'state', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_events');
    }
};
