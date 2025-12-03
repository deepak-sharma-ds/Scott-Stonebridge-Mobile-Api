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
        Schema::table('customer_entitlements', function (Blueprint $table) {
            $table->boolean('is_download_allowed')->default(false)->after('package_tag');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('customer_entitlements', function (Blueprint $table) {
            $table->dropColumn('is_download_allowed');
        });
    }
};
