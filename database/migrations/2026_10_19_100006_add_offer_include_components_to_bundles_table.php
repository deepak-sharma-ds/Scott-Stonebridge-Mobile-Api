<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            // Off: the offer shows only on the products selected in "Show this offer on".
            $table->boolean('offer_include_components')->default(false)->after('offer_heading');
        });
    }

    public function down(): void
    {
        Schema::table('bundles', function (Blueprint $table) {
            $table->dropColumn('offer_include_components');
        });
    }
};
