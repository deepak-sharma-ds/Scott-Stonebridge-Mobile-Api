<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaign_deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shopify_order_id')->index();
            $table->unsignedBigInteger('shopify_line_item_id')->unique();
            $table->foreignId('campaign_product_id')
                ->nullable()
                ->constrained('campaign_products')
                ->nullOnDelete();
            $table->string('customer_email');
            $table->string('customer_name')->nullable();
            $table->string('status')->default('pending')->index();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('expedited_at')->nullable();
            $table->timestamp('fulfilled_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error_message')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_deliveries');
    }
};
