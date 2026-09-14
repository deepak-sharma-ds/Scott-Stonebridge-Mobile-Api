<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;


class ListShopifyWebhooks extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:list-shopify-webhooks';

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
        $shop = config('services.shopify.shop_domain');
        $token = config('services.shopify.admin_token');

        $query = <<<'GRAPHQL'
        {
            webhookSubscriptions(first: 50) {
                edges {
                    node {
                        id
                        topic
                        endpoint {
                            __typename
                            ... on WebhookHttpEndpoint {
                                callbackUrl
                            }
                        }
                    }
                }
            }
        }
        GRAPHQL;

        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Content-Type' => 'application/json',
        ])->post(
            "https://{$shop}/admin/api/2025-01/graphql.json",
            ['query' => $query]
        );

        $this->info($response->body());
    }
}
