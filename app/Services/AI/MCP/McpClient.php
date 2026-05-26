<?php

declare(strict_types=1);

namespace App\Services\AI\MCP;

use App\Exceptions\AI\AIServiceUnavailableException;
use App\Exceptions\AI\AuthRequiredException;
use App\Exceptions\AI\McpToolException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class McpClient
{
    public function __construct(
        private readonly HttpFactory $http,
    ) {}

    /**
     * Call a single MCP tool over JSON-RPC 2.0.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed> Decoded JSON-RPC `result` payload.
     *
     * @throws AuthRequiredException On HTTP 401 (customer endpoint not authenticated).
     * @throws McpToolException On JSON-RPC level errors.
     * @throws AIServiceUnavailableException On transport / 5xx after retry exhausted.
     */
    public function callTool(
        string $endpoint,
        string $toolName,
        array $arguments,
        ?string $authToken = null,
    ): array {
        $requestId = (string) Str::uuid();
        $timeoutMs = (int) config('chatbot.mcp.timeout_ms', 15000);
        $retryOn = (array) config('chatbot.mcp.retry_on_status', [502, 503, 504]);
        $startedAt = microtime(true);

        $body = [
            'jsonrpc' => '2.0',
            'id' => $requestId,
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => $arguments,
            ],
        ];

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];

        if ($authToken !== null) {
            $headers['Authorization'] = $authToken;
        }

        $request = $this->http
            ->withHeaders($headers)
            ->timeout((int) max(1, ceil($timeoutMs / 1000)))
            ->retry(2, 200, function (Throwable $exception) use ($retryOn) {
                if ($exception instanceof ConnectionException) {
                    return true;
                }
                if ($exception instanceof RequestException) {
                    return in_array($exception->response->status(), $retryOn, true);
                }

                return false;
            }, throw: false);

        try {
            $response = $request->post($endpoint, $body);
        } catch (ConnectionException $e) {
            $this->logCall($toolName, $requestId, $startedAt, 'error', ['reason' => 'connection']);
            throw new AIServiceUnavailableException(
                'MCP endpoint unreachable.',
                ['tool' => $toolName, 'reason' => 'connection'],
                $e,
            );
        }

        $status = $response->status();

        if ($status === 401) {
            $this->logCall($toolName, $requestId, $startedAt, 'auth_required');
            throw new AuthRequiredException(
                'MCP endpoint returned 401.',
                ['tool' => $toolName],
            );
        }

        if ($status >= 500) {
            $this->logCall($toolName, $requestId, $startedAt, 'error', ['http_status' => $status]);
            throw new AIServiceUnavailableException(
                'MCP endpoint returned 5xx.',
                ['tool' => $toolName, 'http_status' => $status],
            );
        }

        if ($status >= 400) {
            $this->logCall($toolName, $requestId, $startedAt, 'error', ['http_status' => $status]);
            throw new McpToolException(
                "MCP transport error (HTTP {$status}).",
                null,
                ['tool' => $toolName, 'http_status' => $status],
            );
        }

        $payload = $response->json();
        if (! is_array($payload)) {
            $this->logCall($toolName, $requestId, $startedAt, 'error', ['reason' => 'invalid_json']);
            throw new McpToolException(
                'MCP response is not valid JSON.',
                null,
                ['tool' => $toolName],
            );
        }

        if (isset($payload['error']) && is_array($payload['error'])) {
            $code = isset($payload['error']['code']) ? (int) $payload['error']['code'] : null;
            $message = (string) ($payload['error']['message'] ?? 'Unknown MCP error.');
            $this->logCall($toolName, $requestId, $startedAt, 'error', ['rpc_code' => $code]);
            throw new McpToolException(
                $message,
                $code,
                ['tool' => $toolName],
            );
        }

        $result = $payload['result'] ?? null;
        if (! is_array($result)) {
            $this->logCall($toolName, $requestId, $startedAt, 'error', ['reason' => 'missing_result']);
            throw new McpToolException(
                'MCP response missing `result`.',
                null,
                ['tool' => $toolName],
            );
        }

        // Shopify Storefront MCP signals tool-level failures with
        // `isError: true` inside the result body (e.g. GraphQL validation
        // errors). Surface them as McpToolException so the AI explains it.
        if (($result['isError'] ?? false) === true) {
            $inner = '';
            if (isset($result['content'][0]['text']) && is_string($result['content'][0]['text'])) {
                $inner = $result['content'][0]['text'];
            }
            $this->logCall($toolName, $requestId, $startedAt, 'error', ['reason' => 'tool_is_error']);
            throw new McpToolException(
                $inner !== '' ? "MCP tool error: {$inner}" : 'MCP tool returned isError.',
                null,
                ['tool' => $toolName],
            );
        }

        $this->logCall($toolName, $requestId, $startedAt, 'ok');

        return $result;
    }

    private function logCall(string $toolName, string $requestId, float $startedAt, string $status, array $extra = []): void
    {
        $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);

        Log::channel('ai')->info('mcp.tool_call', [
            'tool' => $toolName,
            'request_id' => $requestId,
            'latency_ms' => $latencyMs,
            'status' => $status,
        ] + $extra);
    }
}
