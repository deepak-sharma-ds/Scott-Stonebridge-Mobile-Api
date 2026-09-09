<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('campaign_key')->unique();
            $table->string('name');
            $table->string('klaviyo_campaign_id')->nullable();
            $table->string('status')->default('draft')->index();
            $table->timestamps();
        });

        Schema::create('campaign_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('marketing_campaign_id')
                ->constrained('marketing_campaigns')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->unsignedBigInteger('shopify_product_id')->index();
            $table->string('product_title')->nullable();
            $table->longText('prompt_template')->nullable();
            $table->string('email_subject')->nullable();
            $table->string('model')->nullable();
            $table->unsignedSmallInteger('max_tokens')->nullable();
            $table->timestamps();
            $table->unique(['marketing_campaign_id', 'shopify_product_id'], 'campaign_products_campaign_product_unique');
        });

        Schema::create('campaign_product_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_product_id')
                ->unique()
                ->constrained('campaign_products')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();
            $table->longText('ai_response')->nullable();
            $table->string('model_used')->nullable();
            $table->unsignedInteger('prompt_tokens')->nullable();
            $table->unsignedInteger('completion_tokens')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('campaign_product_responses');
        Schema::dropIfExists('campaign_products');
        Schema::dropIfExists('marketing_campaigns');
    }
};
