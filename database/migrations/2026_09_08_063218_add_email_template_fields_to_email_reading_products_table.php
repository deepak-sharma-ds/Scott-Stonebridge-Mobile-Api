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
        Schema::table('email_reading_products', function (Blueprint $table) {
            $table->string('header_image')->nullable()->after('name');
            $table->text('email_content')->nullable()->after('header_image');
            $table->text('email_footer')->nullable()->after('email_content');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_reading_products', function (Blueprint $table) {
            $table->dropColumn(['header_image', 'email_content', 'email_footer']);
        });
    }
};
