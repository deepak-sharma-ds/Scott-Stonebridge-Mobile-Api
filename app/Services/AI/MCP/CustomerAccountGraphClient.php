<?php

declare(strict_types=1);

namespace App\Services\AI\MCP;

use App\Exceptions\AI\AuthRequiredException;
use App\Exceptions\AI\McpToolException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Customer Account GraphQL fallback for the Customer MCP order tools.
 *
 * The Shopify Customer Account MCP surface has been observed to reject valid
 * Customer Account API tokens with HTTP 401 + `{"errors":[{"message":"Unauthorized"}]}`
 * — likely because MCP is gated separately to the GraphQL surface on stock
 * Headless apps. The GraphQL endpoint works with the same `customer-account-api:full`
 * scope, so we route order lookups through it when MCP returns auth_required.
 *
 * Endpoint discovery is shared with `CustomerMcpClient` via the same
 * `.well-known/customer-account-api` document — its `graphql_api` field is
 * the GraphQL URL.
 */
class CustomerAccountGraphClient
{
    private const DISCOVERY_CACHE_TTL = 3600;

    /**
     * Run a GraphQL query against the discovered Customer Account API endpoint.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed> Decoded `data` payload from the GraphQL response.
     *
     * @throws AuthRequiredException When the token is rejected (HTTP 401 or GraphQL `UNAUTHENTICATED`).
     * @throws McpToolException On transport, JSON, or GraphQL-error failures.
     */
    public function query(string $shopDomain, string $accessToken, string $query, array $variables = []): array
    {
        $endpoint = $this->resolveGraphEndpoint($shopDomain);

        // Shopify Customer Account API expects the raw access_token in the
        // Authorization header — NO `Bearer ` prefix. Strip defensively.
        $token = str_starts_with($accessToken, 'Bearer ')
            ? substr($accessToken, 7)
            : $accessToken;

        $response = Http::timeout(15)
            ->acceptJson()
            ->withHeaders([
                'Content-Type' => 'application/json',
                'Authorization' => $token,
            ])
            ->post($endpoint, [
                'query' => $query,
                'variables' => (object) $variables,
            ]);

        $status = $response->status();
        if ($status === 401 || $status === 403) {
            Log::channel('ai')->info('customer_graph.auth_required', [
                'shop_domain' => $shopDomain,
                'http_status' => $status,
                'body_snippet' => substr((string) $response->body(), 0, 240),
            ]);

            throw new AuthRequiredException(
                'Customer Account GraphQL returned '.$status.'.',
                ['endpoint' => $endpoint],
            );
        }

        if (! $response->successful()) {
            throw new McpToolException(
                'Customer Account GraphQL transport error.',
                null,
                ['shop_domain' => $shopDomain, 'http_status' => $status],
            );
        }

        $payload = (array) $response->json();

        if (isset($payload['errors']) && is_array($payload['errors']) && $payload['errors'] !== []) {
            $first = $payload['errors'][0] ?? [];
            $code = is_array($first) ? (string) ($first['extensions']['code'] ?? '') : '';
            if (in_array($code, ['UNAUTHENTICATED', 'ACCESS_DENIED', 'UNAUTHORIZED'], true)) {
                throw new AuthRequiredException(
                    'Customer Account GraphQL: '.$code,
                    ['shop_domain' => $shopDomain],
                );
            }

            $message = is_array($first) ? (string) ($first['message'] ?? 'GraphQL error') : 'GraphQL error';
            throw new McpToolException(
                "Customer Account GraphQL error: {$message}",
                null,
                ['shop_domain' => $shopDomain],
            );
        }

        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            throw new McpToolException(
                'Customer Account GraphQL response missing `data`.',
                null,
                ['shop_domain' => $shopDomain],
            );
        }

        return $data;
    }

    /**
     * Resolve the GraphQL endpoint via the same well-known document the MCP
     * client uses. Cached for an hour per shop.
     */
    private function resolveGraphEndpoint(string $shopDomain): string
    {
        $shopDomain = trim($shopDomain);
        if ($shopDomain === '') {
            throw new McpToolException('Customer Account GraphQL requires a shop domain.');
        }

        $cacheKey = "ai:customer_account:graphql_endpoint:{$shopDomain}";

        return Cache::remember($cacheKey, self::DISCOVERY_CACHE_TTL, function () use ($shopDomain): string {
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
            $endpoint = $config['graphql_api']
                ?? $config['graphql_endpoint']
                ?? null;

            if (! is_string($endpoint) || $endpoint === '') {
                throw new McpToolException(
                    'Customer Account discovery missing GraphQL endpoint.',
                    null,
                    ['shop_domain' => $shopDomain],
                );
            }

            return $endpoint;
        });
    }
}
