<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_image_uploads', function (Blueprint $table) {
            $table->id();
            $table->string('token', 40)->unique();
            $table->string('disk', 40);
            $table->string('path');
            $table->string('mime', 50);
            $table->unsignedInteger('size');
            $table->string('shopify_file_id')->nullable();
            $table->string('shopify_file_url', 1000)->nullable();
            $table->unsignedBigInteger('email_reading_delivery_id')->nullable()->index();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_image_uploads');
    }
};
