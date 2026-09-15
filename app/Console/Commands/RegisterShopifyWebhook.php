<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
class RegisterShopifyWebhook extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:register-webhook {url}';
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Command description';

    /**
     * Execute the console command.
     */
    public function handle()
      {
        $ngrokUrl = rtrim($this->argument('url'), '/');
        $webhookUri = $ngrokUrl . '/webhook/shopify/consent-update';
        $shop = config('services.shopify.shop_domain');
        $token = config('services.shopify.admin_token');

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
        ])->post("https://{$shop}/admin/api/2025-01/graphql.json", [
            'query' => '
                        mutation registerWebhook($uri: String!) {                 
                             webhookSubscriptionCreate(
                        topic: CUSTOMERS_EMAIL_MARKETING_CONSENT_UPDATE
                        webhookSubscription: { uri: $uri, format: JSON }
                    ) {
                        webhookSubscription { id }
                        userErrors { field message }
                    }
                }
            ',
            'variables' => ['uri' => $webhookUri],
        ]);

        $body = $response->json();
        $errors = $body['data']['webhookSubscriptionCreate']['userErrors'] ?? [];
        $id = $body['data']['webhookSubscriptionCreate']['webhookSubscription']['id'] ?? null;
        \Log::info('Shopify webhook registration response', $body);
        if ($id) {
            $this->info("✅ Webhook registered successfully!");
            $this->info("Webhook ID: {$id}");
            $this->info("Pointing at: {$webhookUri}");
        } else {
            $this->error("❌ Something went wrong:");
            $this->error("HTTP status: " . $response->status());
            $this->error("Shop domain used: " . ($shop ?: '(empty - check your .env)'));
            $this->error("Token set: " . ($token ? 'yes' : 'no - check your .env'));
            $this->error("Full response body:");
            $this->error($response->body());
        }
    }
}
