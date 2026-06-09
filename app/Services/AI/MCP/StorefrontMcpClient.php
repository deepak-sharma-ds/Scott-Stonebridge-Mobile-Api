<?php

declare(strict_types=1);

namespace App\Services\AI\MCP;

use App\Exceptions\AI\McpToolException;

class StorefrontMcpClient
{
    private const UCP_TOOLS = [
        'search_catalog',
        'get_product',
        'lookup_catalog',
    ];

    public function __construct(
        private readonly McpClient $client,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function callTool(string $toolName, array $arguments, string $shopDomain): array
    {
        $endpoint = $this->resolveEndpoint($toolName, $shopDomain);

        return $this->client->callTool($endpoint, $toolName, $arguments);
    }

    private function resolveEndpoint(string $toolName, string $shopDomain): string
    {
        $shopDomain = trim($shopDomain);
        if ($shopDomain === '') {
            throw new McpToolException('Storefront MCP requires a shop domain.', null, ['tool' => $toolName]);
        }

        $configKey = in_array($toolName, self::UCP_TOOLS, true)
            ? 'chatbot.mcp.ucp_endpoint'
            : 'chatbot.mcp.storefront_endpoint';

        $template = (string) config($configKey);

        return str_replace('{shop}', $shopDomain, $template);
    }
}
