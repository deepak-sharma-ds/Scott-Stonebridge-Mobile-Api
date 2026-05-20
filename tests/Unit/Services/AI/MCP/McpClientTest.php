<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI\MCP;

use App\Exceptions\AI\AIServiceUnavailableException;
use App\Exceptions\AI\AuthRequiredException;
use App\Exceptions\AI\McpToolException;
use App\Services\AI\MCP\McpClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class McpClientTest extends TestCase
{
    private const ENDPOINT = 'https://shop.example.com/api/mcp';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'chatbot.mcp.timeout_ms' => 15000,
            'chatbot.mcp.retry_on_status' => [502, 503, 504],
        ]);
    }

    public function test_envelopes_request_as_jsonrpc_v2_tools_call(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'jsonrpc' => '2.0',
                'id' => 'anything',
                'result' => ['products' => []],
            ]),
        ]);

        $client = $this->mcpClient();
        $result = $client->callTool(self::ENDPOINT, 'search_catalog', ['query' => 'tarot']);

        $this->assertSame(['products' => []], $result);

        Http::assertSent(function ($request) {
            $body = $request->data();

            return $request->url() === self::ENDPOINT
                && $body['jsonrpc'] === '2.0'
                && $body['method'] === 'tools/call'
                && $body['params']['name'] === 'search_catalog'
                && $body['params']['arguments'] === ['query' => 'tarot']
                && ! empty($body['id'])
                && $request->hasHeader('Content-Type', 'application/json')
                && $request->hasHeader('Accept', 'application/json');
        });
    }

    public function test_passes_authorization_header_when_token_provided(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['jsonrpc' => '2.0', 'result' => ['ok' => true]]),
        ]);

        $client = $this->mcpClient();
        $client->callTool(self::ENDPOINT, 'get_order_status', ['order_number' => '1001'], 'Bearer abc.def');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer abc.def'));
    }

    public function test_omits_authorization_header_when_no_token(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['jsonrpc' => '2.0', 'result' => ['ok' => true]]),
        ]);

        $client = $this->mcpClient();
        $client->callTool(self::ENDPOINT, 'search_catalog', []);

        Http::assertSent(fn ($request) => ! $request->hasHeader('Authorization'));
    }

    public function test_retries_once_on_503_then_succeeds(): void
    {
        Http::fakeSequence(self::ENDPOINT)
            ->push('', 503)
            ->push(['jsonrpc' => '2.0', 'result' => ['ok' => true]], 200);

        $client = $this->mcpClient();
        $result = $client->callTool(self::ENDPOINT, 'search_catalog', []);

        $this->assertSame(['ok' => true], $result);
    }

    public function test_throws_service_unavailable_when_retries_exhausted_on_5xx(): void
    {
        Http::fake([self::ENDPOINT => Http::response('', 503)]);

        $this->expectException(AIServiceUnavailableException::class);
        $this->mcpClient()->callTool(self::ENDPOINT, 'search_catalog', []);
    }

    public function test_throws_auth_required_on_401(): void
    {
        Http::fake([self::ENDPOINT => Http::response('unauthenticated', 401)]);

        $this->expectException(AuthRequiredException::class);
        $this->mcpClient()->callTool(self::ENDPOINT, 'get_order_status', ['order_number' => '1001'], 'Bearer expired');
    }

    public function test_throws_mcp_tool_exception_on_jsonrpc_error_payload(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response([
                'jsonrpc' => '2.0',
                'id' => 'req-1',
                'error' => ['code' => -32602, 'message' => 'invalid params'],
            ]),
        ]);

        try {
            $this->mcpClient()->callTool(self::ENDPOINT, 'search_catalog', []);
            $this->fail('Expected McpToolException.');
        } catch (McpToolException $e) {
            $this->assertSame('invalid params', $e->getMessage());
            $this->assertSame(-32602, $e->rpcCode());
        }
    }

    public function test_throws_mcp_tool_exception_when_result_missing(): void
    {
        Http::fake([
            self::ENDPOINT => Http::response(['jsonrpc' => '2.0', 'id' => 'req-1']),
        ]);

        $this->expectException(McpToolException::class);
        $this->mcpClient()->callTool(self::ENDPOINT, 'search_catalog', []);
    }

    public function test_throws_mcp_tool_exception_on_non_5xx_4xx(): void
    {
        Http::fake([self::ENDPOINT => Http::response('bad', 400)]);

        $this->expectException(McpToolException::class);
        $this->mcpClient()->callTool(self::ENDPOINT, 'search_catalog', []);
    }

    private function mcpClient(): McpClient
    {
        return new McpClient(app(HttpFactory::class));
    }
}
