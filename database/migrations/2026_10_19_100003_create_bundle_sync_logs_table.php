<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bundle_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bundle_id')->nullable()->constrained('bundles')->nullOnDelete();
            $table->string('operation', 50)->index();
            $table->string('status', 20)->index(); // success | failed
            $table->json('details')->nullable();
            $table->text('error')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bundle_sync_logs');
    }
};
