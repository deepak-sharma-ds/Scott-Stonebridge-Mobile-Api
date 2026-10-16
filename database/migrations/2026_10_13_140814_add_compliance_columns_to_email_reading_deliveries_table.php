<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `compliance_flagged_reason` records why the Compliance Gate quarantined a
     * delivery (status `compliance_hold`). `compliance_reviewed_at` is stamped
     * when an admin approves the held delivery, so the gate does not re-hold it
     * when generation is re-queued.
     */
    public function up(): void
    {
        Schema::table('email_reading_deliveries', function (Blueprint $table) {
            $table->string('compliance_flagged_reason', 500)->nullable();
            $table->timestamp('compliance_reviewed_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_reading_deliveries', function (Blueprint $table) {
            $table->dropColumn(['compliance_flagged_reason', 'compliance_reviewed_at']);
        });
    }
};
