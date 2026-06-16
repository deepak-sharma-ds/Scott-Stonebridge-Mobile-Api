<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_reading_products', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_product_id')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->json('questions_schema');
            $table->longText('prompt_template');
            $table->string('email_subject');
            $table->string('email_view')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('max_tokens')->default(1500);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('shopify_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('topic')->index();
            $table->unsignedBigInteger('shopify_order_id')->index();
            $table->string('shopify_webhook_id')->nullable()->unique();
            $table->longText('payload');
            $table->boolean('hmac_valid')->default(false);
            $table->timestamp('received_at')->nullable();
            $table->timestamps();
            $table->index(['topic', 'shopify_order_id']);
        });

        Schema::create('email_reading_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_order_id')->index();
            $table->unsignedBigInteger('shopify_line_item_id')->unique();
            $table->foreignId('email_reading_product_id')
                ->constrained('email_reading_products')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('customer_email');
            $table->string('customer_name')->nullable();
            $table->json('questions');
            $table->longText('ai_response')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->string('model_used')->nullable();
            $table->string('status')->default('pending')->index();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_reading_deliveries');
        Schema::dropIfExists('shopify_webhook_events');
        Schema::dropIfExists('email_reading_products');
    }
};
