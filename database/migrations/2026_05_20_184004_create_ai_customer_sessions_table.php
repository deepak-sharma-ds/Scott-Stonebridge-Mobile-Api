<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_customer_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('session_id');
            $table->text('customer_access_token');
            $table->timestamp('expires_at')->index();
            $table->timestamps();

            $table->unique('session_id');
            $table->foreign('session_id')
                ->references('session_id')
                ->on('ai_conversations')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_customer_sessions');
    }
};
