<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase E columns on ai_conversations. revenue_attributed is
     * updated by StoreConversionEventJob on order_placed events.
     * conversion_type marks direct/assisted/abandoned for funnel
     * attribution. lead_captured is a cheap denormalised flag the
     * dashboard can query without a JOIN.
     *
     * Skipped: locale — already present in the create migration.
     */
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table): void {
            $table->decimal('revenue_attributed', 10, 2)->default(0)->after('ended_at');
            $table->enum('conversion_type', ['direct', 'assisted', 'abandoned'])
                ->nullable()
                ->after('revenue_attributed');
            $table->boolean('lead_captured')->default(false)->after('conversion_type');
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table): void {
            $table->dropColumn(['revenue_attributed', 'conversion_type', 'lead_captured']);
        });
    }
};
