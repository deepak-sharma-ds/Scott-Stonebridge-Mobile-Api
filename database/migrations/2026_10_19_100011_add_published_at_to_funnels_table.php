<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * First time a funnel went live: tells a paused funnel (published before) from a draft (never published).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('funnels', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('status');
        });

        // Existing funnels: active ones, and inactive ones that were live before (they have stats or orders).
        DB::table('funnels')
            ->where(fn ($q) => $q->where('status', 'active')
                ->orWhereExists(fn ($s) => $s->from('funnel_stats')->whereColumn('funnel_stats.funnel_id', 'funnels.id'))
                ->orWhereExists(fn ($s) => $s->from('funnel_orders')->whereColumn('funnel_orders.funnel_id', 'funnels.id')))
            ->update(['published_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('funnels', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }
};
