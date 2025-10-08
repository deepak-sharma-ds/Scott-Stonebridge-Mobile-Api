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
       Schema::table('scheduled_meetings', function (Blueprint $table) {
            $table->unsignedBigInteger('availability_date_id')->after('user_id')->nullable();
            $table->unsignedBigInteger('time_slot_id')->after('availability_date_id')->nullable();

            $table->foreign('availability_date_id')->references('id')->on('availability_dates')->onDelete('set null');
            $table->foreign('time_slot_id')->references('id')->on('time_slots')->onDelete('set null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('scheduled_meetings', function (Blueprint $table) {
            $table->dropForeign(['availability_date_id']);
            $table->dropForeign(['time_slot_id']);
            $table->dropColumn(['availability_date_id', 'time_slot_id']);
        });
    }
};
