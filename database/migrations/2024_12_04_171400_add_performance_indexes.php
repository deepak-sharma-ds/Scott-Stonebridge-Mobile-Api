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
        // Packages table indexes
        Schema::table('packages', function (Blueprint $table) {
            $table->index('shopify_tag', 'idx_packages_shopify_tag');
            $table->index('status', 'idx_packages_status');
            $table->index(['status', 'created_at'], 'idx_packages_status_created');
        });

        // Audios table indexes
        Schema::table('audio', function (Blueprint $table) {
            $table->index('package_id', 'idx_audio_package_id');
            $table->index(['package_id', 'order_index'], 'idx_audio_package_order');
            $table->index('is_hls_ready', 'idx_audio_hls_ready');
        });

        // Scheduled meetings table indexes
        Schema::table('scheduled_meetings', function (Blueprint $table) {
            $table->index('status', 'idx_meetings_status');
            $table->index(['availability_date_id', 'time_slot_id'], 'idx_meetings_availability_slot');
            $table->index('datetime', 'idx_meetings_datetime');
            $table->index('email', 'idx_meetings_email');
        });

        // Availability dates table indexes
        Schema::table('availability_dates', function (Blueprint $table) {
            $table->index('date', 'idx_availability_date');
            // $table->index('is_available', 'idx_availability_status');
        });

        // Time slots table indexes
        Schema::table('time_slots', function (Blueprint $table) {
            $table->index('availability_date_id', 'idx_timeslots_availability');
            $table->index(['availability_date_id', 'start_time'], 'idx_timeslots_availability_start');
        });

        // Audio download logs indexes (for analytics)
        Schema::table('audio_download_logs', function (Blueprint $table) {
            $table->index('audio_id', 'idx_download_logs_audio');
            // $table->index('user_id', 'idx_download_logs_user');
            $table->index('downloaded_at', 'idx_download_logs_date');
        });

        // API logs indexes (for analytics)
        Schema::table('api_logs', function (Blueprint $table) {
            $table->index('action', 'idx_api_logs_action');
            $table->index('actor_id', 'idx_api_logs_actor');
            $table->index('created_at', 'idx_api_logs_created');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Packages
        Schema::table('packages', function (Blueprint $table) {
            $table->dropIndex('idx_packages_shopify_tag');
            $table->dropIndex('idx_packages_status');
            $table->dropIndex('idx_packages_status_created');
        });

        // Audios
        Schema::table('audio', function (Blueprint $table) {
            $table->dropIndex('idx_audio_package_id');
            $table->dropIndex('idx_audio_package_order');
            $table->dropIndex('idx_audio_hls_ready');
        });

        // Scheduled meetings
        Schema::table('scheduled_meetings', function (Blueprint $table) {
            $table->dropIndex('idx_meetings_status');
            $table->dropIndex('idx_meetings_availability_slot');
            $table->dropIndex('idx_meetings_datetime');
            $table->dropIndex('idx_meetings_email');
        });

        // Availability dates
        Schema::table('availability_dates', function (Blueprint $table) {
            $table->dropIndex('idx_availability_date');
            // $table->dropIndex('idx_availability_status');
        });

        // Time slots
        Schema::table('time_slots', function (Blueprint $table) {
            $table->dropIndex('idx_timeslots_availability');
            $table->dropIndex('idx_timeslots_availability_start');
        });

        // Audio download logs
        Schema::table('audio_download_logs', function (Blueprint $table) {
            $table->dropIndex('idx_download_logs_audio');
            // $table->dropIndex('idx_download_logs_user');
            $table->dropIndex('idx_download_logs_date');
        });

        // API logs
        Schema::table('api_logs', function (Blueprint $table) {
            $table->dropIndex('idx_api_logs_action');
            $table->dropIndex('idx_api_logs_actor');
            $table->dropIndex('idx_api_logs_created');
        });
    }
};
