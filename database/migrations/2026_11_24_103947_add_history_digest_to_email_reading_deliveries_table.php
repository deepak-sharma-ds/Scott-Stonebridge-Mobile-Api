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
        Schema::table('email_reading_deliveries', function (Blueprint $table) {
            $table->text('history_digest')->nullable();
            $table->timestamp('history_digest_generated_at')->nullable();
            $table->boolean('history_digest_is_manual')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_reading_deliveries', function (Blueprint $table) {
            $table->dropColumn(['history_digest', 'history_digest_generated_at', 'history_digest_is_manual']);
        });
    }
};
