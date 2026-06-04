<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture the Shopify Customer Account refresh token so the chat session can
 * silently extend a 1-hour access token without bouncing the user back through
 * the full OAuth popup every hour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_customer_sessions', function (Blueprint $table) {
            $table->text('refresh_token')->nullable()->after('customer_access_token');
            $table->timestamp('refresh_token_expires_at')->nullable()->after('expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('ai_customer_sessions', function (Blueprint $table) {
            $table->dropColumn(['refresh_token', 'refresh_token_expires_at']);
        });
    }
};
