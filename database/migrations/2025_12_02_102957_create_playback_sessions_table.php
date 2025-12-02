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
        Schema::create('playback_sessions', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('customer_id')->index();
            $table->bigInteger('audio_id')->index();
            $table->string('package_tag');
            $table->float('last_position_seconds')->default(0);
            $table->timestamps();

            $table->unique(['customer_id', 'audio_id', 'package_tag'], 'playback_unique_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('playback_sessions');
    }
};
