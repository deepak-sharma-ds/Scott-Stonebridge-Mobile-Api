<?php

declare(strict_types=1);

namespace App\Services\AI\MCP;

use App\Exceptions\AI\AuthRequiredException;
use App\Exceptions\AI\McpToolException;
use App\Services\AI\ChatbotConfigRepository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class CustomerMcpClient
{
    public function __construct(
        private readonly McpClient $client,
        private readonly ChatbotConfigRepository $chatbotConfig,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     *
     * @throws AuthRequiredException
     */
    public function callTool(string $toolName, array $arguments, string $shopDomain, string $customerAccessToken): array
    {
        $endpoint = $this->resolveEndpoint($shopDomain);
        // Shopify Customer Account API (incl. its MCP surface) expects the
        // raw access token in the Authorization header — NOT prefixed with
        // `Bearer `. Sending `Bearer <token>` causes a 401 + body
        // `{"errors":[{"message":"Unauthorized"}]}` even when the token is
        // valid. Strip the prefix defensively if a caller passed it in.
        $token = str_starts_with($customerAccessToken, 'Bearer ')
            ? substr($customerAccessToken, 7)
            : $customerAccessToken;

        return $this->client->callTool($endpoint, $toolName, $arguments, $token);
    }

    /**
     * Read the well-known OpenID configuration for the customer-facing host and
     * return its MCP endpoint. Cached for an hour.
     */
    private function resolveEndpoint(string $shopDomain): string
    {
        $shopDomain = trim($shopDomain);
        if ($shopDomain === '') {
            throw new McpToolException('Customer MCP requires a shop domain.');
        }

        $cacheKey = "ai:mcp:customer_endpoint:{$shopDomain}";

        return Cache::remember($cacheKey, $this->chatbotConfig->mcpDiscoveryCacheTtlSeconds(), function () use ($shopDomain): string {
            $discoveryPath = (string) config('chatbot.mcp.customer_discovery', '/.well-known/customer-account-api');
            $url = "https://{$shopDomain}{$discoveryPath}";

            $response = Http::timeout(10)->acceptJson()->get($url);
            if (! $response->successful()) {
                throw new McpToolException(
                    'Customer Account discovery failed.',
                    null,
                    ['shop_domain' => $shopDomain, 'http_status' => $response->status()],
                );
            }

            $config = (array) $response->json();
            // Shopify `.well-known/customer-account-api` returns
            // `{ graphql_api, mcp_api }`. Older guesses used mcp_endpoint /
            // tools_endpoint — keep them as fallbacks.
            $endpoint = $config['mcp_api']
                ?? $config['mcp_endpoint']
                ?? $config['tools_endpoint']
                ?? null;
            if (! is_string($endpoint) || $endpoint === '') {
                throw new McpToolException(
                    'Customer Account discovery missing MCP endpoint.',
                    null,
                    ['shop_domain' => $shopDomain],
                );
            }

            return $endpoint;
        });
    }
}
