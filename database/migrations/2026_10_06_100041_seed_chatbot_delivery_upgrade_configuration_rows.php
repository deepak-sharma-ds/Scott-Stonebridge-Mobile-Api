<?php

use App\Models\Configuration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Data-only migration: seeds the two Chatbot Config Rows that govern
 * Delivery Upgrade Product recommendations (ADR 0022). Read exclusively
 * through `App\Services\AI\ChatbotConfigRepository` (ADR 0006).
 */
return new class extends Migration
{
    /**
     * @return list<array{name: string, value: string, title: string, description: string, input_type: string}>
     */
    private function rows(): array
    {
        return [
            ['name' => 'Chatbot.Delivery.upgrade_enabled', 'value' => '1', 'title' => 'Delivery Upgrade Recommendations Enabled', 'description' => 'Kill switch (1 = on, 0 = off) for recommending Delivery Upgrade Products (products tagged "delivery-upgrade") in the chatbot.', 'input_type' => 'text'],
            ['name' => 'Chatbot.Delivery.eligible_collection_handles', 'value' => '["email-readings","readings"]', 'title' => 'Delivery Upgrade Eligible Collections', 'description' => 'JSON array (or comma-separated list) of Shopify collection handles. A cart containing a product from any of them is offered a Delivery Upgrade Product.', 'input_type' => 'text'],
        ];
    }

    public function up(): void
    {
        // `configurations` has no schema migration of its own (ADR 0006), so
        // fresh test databases may not have it — skip rather than fail.
        if (! Schema::hasTable('configurations')) {
            return;
        }

        foreach ($this->rows() as $row) {
            Configuration::query()->updateOrCreate(
                ['name' => $row['name']],
                $row + ['editable' => 1],
            );
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('configurations')) {
            return;
        }

        Configuration::query()
            ->whereIn('name', array_column($this->rows(), 'name'))
            ->delete();
    }
};
