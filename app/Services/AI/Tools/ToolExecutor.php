<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Contracts\Services\Sales\UpsellServiceInterface;
use App\Exceptions\AI\AIServiceUnavailableException;
use App\Exceptions\AI\AuthRequiredException;
use App\Exceptions\AI\McpToolException;
use App\Models\AiCustomerSession;
use App\Services\AI\ChatSessionContext;
use App\Services\AI\MCP\CustomerMcpClient;
use App\Services\AI\MCP\Mappers\CartMapper;
use App\Services\AI\MCP\Mappers\OrderMapper;
use App\Services\AI\MCP\Mappers\PolicyMapper;
use App\Services\AI\MCP\Mappers\ProductMapper;
use App\Services\AI\MCP\StorefrontMcpClient;
use App\Services\AI\Streaming\ChunkEmitter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Single entry point for every OpenAI tool_call. Dispatches to the matching
 * MCP client (Storefront / Customer) or internal service, caches catalog
 * reads, enforces a per-session MCP bucket, and pushes the resulting SSE
 * chunk via ChunkEmitter.
 */
class ToolExecutor
{
    private const RATE_LIMIT_KEY = 'ai:rate:%s:mcp';

    private const RATE_LIMIT_TTL = 60;

    private const RATE_LIMIT_MAX = 60;

    public function __construct(
        private readonly StorefrontMcpClient $storefront,
        private readonly CustomerMcpClient $customer,
        private readonly ChunkEmitter $emitter,
        private readonly UpsellServiceInterface $upsell,
    ) {}

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function execute(string $toolName, array $arguments, ChatSessionContext $ctx): ToolResult
    {
        try {
            if (in_array($toolName, ToolDefinitions::INTERNAL_TOOLS, true)) {
                return $this->executeInternal($toolName, $arguments, $ctx);
            }

            if (! $this->withinRateLimit($ctx->sessionId)) {
                $this->emitter->emit('text', ['content' => "I'm getting too many requests — give me a moment and try again."]);

                return ToolResult::error('Rate limit exceeded for MCP calls.');
            }

            if (in_array($toolName, ToolDefinitions::CUSTOMER_MCP_TOOLS, true)) {
                return $this->executeCustomerMcp($toolName, $arguments, $ctx);
            }

            if (in_array($toolName, ToolDefinitions::STOREFRONT_MCP_TOOLS, true)) {
                return $this->executeStorefrontMcp($toolName, $arguments, $ctx);
            }

            return ToolResult::error("Unknown tool: {$toolName}");
        } catch (AuthRequiredException $e) {
            return $this->emitAuthRequired($ctx);
        } catch (McpToolException|AIServiceUnavailableException $e) {
            $this->logToolError($toolName, $ctx, $e);

            return ToolResult::error("Tool {$toolName} failed: {$e->getMessage()}");
        } catch (Throwable $e) {
            report($e);
            $this->logToolError($toolName, $ctx, $e);

            return ToolResult::error("Tool {$toolName} crashed.");
        }
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function executeStorefrontMcp(string $toolName, array $args, ChatSessionContext $ctx): ToolResult
    {
        $args = $this->normaliseStorefrontArgs($toolName, $args);
        $result = $this->withCache($toolName, $args, $ctx->shopDomain, function () use ($toolName, $args, $ctx): array {
            return $this->storefront->callTool($toolName, $args, $ctx->shopDomain);
        });

        return match ($toolName) {
            ToolDefinitions::TOOL_SEARCH_CATALOG => $this->handleProductList($result, $ctx),
            ToolDefinitions::TOOL_GET_PRODUCT_DETAILS => $this->handleProductDetail($result),
            ToolDefinitions::TOOL_GET_CART, ToolDefinitions::TOOL_UPDATE_CART => $this->handleCart($result),
            ToolDefinitions::TOOL_SEARCH_POLICIES => $this->handlePolicy($result),
            default => ToolResult::error("Unhandled storefront tool: {$toolName}"),
        };
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function executeCustomerMcp(string $toolName, array $args, ChatSessionContext $ctx): ToolResult
    {
        $token = $ctx->customerAccessToken ?? $this->resolveCustomerToken($ctx->sessionId);
        if ($token === null) {
            return $this->emitAuthRequired($ctx);
        }

        $result = $this->customer->callTool($toolName, $args, $ctx->shopDomain, $token);
        $dto = OrderMapper::fromOrderStatus($result);
        if ($dto === null) {
            $this->emitter->emit('text', ['content' => "I couldn't find an order matching that request."]);

            return ToolResult::error('Order not found.');
        }

        $payload = ['order_tracking' => $dto->toArray()];
        $this->emitter->emit('order_tracking', $payload);

        return ToolResult::success(
            "Order {$dto->orderNumber} status: {$dto->status}.",
            ['type' => 'order_tracking'] + $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function executeInternal(string $toolName, array $args, ChatSessionContext $ctx): ToolResult
    {
        return match ($toolName) {
            ToolDefinitions::TOOL_SUGGEST_QUICK_REPLIES => $this->handleQuickReplies($args),
            ToolDefinitions::TOOL_SUGGEST_UPSELL => $this->handleUpsell($args, $ctx),
            ToolDefinitions::TOOL_START_CHECKOUT => $this->handleStartCheckout($args, $ctx),
            default => ToolResult::error("Unknown internal tool: {$toolName}"),
        };
    }

    /**
     * Synthesises a `checkout_link` chunk by reading the live cart and
     * surfacing its `checkout_url`. Shopify has no `start_checkout` MCP tool
     * — the cart already carries the hosted checkout URL.
     *
     * @param  array<string, mixed>  $args
     */
    private function handleStartCheckout(array $args, ChatSessionContext $ctx): ToolResult
    {
        $cartId = (string) ($args['cart_id'] ?? $ctx->cartId ?? '');
        if ($cartId === '') {
            return ToolResult::error('start_checkout requires cart_id.');
        }

        try {
            $cartResult = $this->storefront->callTool(
                ToolDefinitions::TOOL_GET_CART,
                ['cart_id' => $cartId],
                $ctx->shopDomain,
            );
        } catch (Throwable $e) {
            $this->logToolError('start_checkout.get_cart', $ctx, $e);

            return ToolResult::error('Could not read cart for checkout.');
        }

        $cart = CartMapper::fromCart($cartResult);
        if ($cart === null || $cart->checkoutUrl === null || $cart->checkoutUrl === '') {
            return ToolResult::error('Cart has no checkout URL.');
        }

        $payload = [
            'checkout_url' => $cart->checkoutUrl,
            'total_amount' => $cart->subtotalMinorUnits !== null ? $cart->subtotalMinorUnits / 100 : null,
            'currency' => $cart->currency,
            'item_count' => $cart->itemCount,
        ];

        $this->emitter->emit('checkout_link', $payload);

        return ToolResult::success(
            'Checkout link ready — UI will open it in a new tab.',
            ['type' => 'checkout_link'] + $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function handleQuickReplies(array $args): ToolResult
    {
        $replies = array_values(array_filter(array_map(
            static fn ($r): string => is_string($r) ? trim($r) : '',
            (array) ($args['replies'] ?? []),
        ), static fn (string $r): bool => $r !== ''));

        if (count($replies) < 2) {
            return ToolResult::error('suggest_quick_replies requires 2-5 strings.');
        }

        $payload = ['replies' => array_slice($replies, 0, 5)];
        $this->emitter->emit('quick_replies', $payload);

        return ToolResult::success('Quick replies presented.', ['type' => 'quick_replies'] + $payload);
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function handleUpsell(array $args, ChatSessionContext $ctx): ToolResult
    {
        $cartId = (string) ($args['cart_id'] ?? $ctx->cartId ?? '');
        if ($cartId === '') {
            return ToolResult::error('suggest_upsell requires cart_id.');
        }

        try {
            $cartResult = $this->storefront->callTool(
                ToolDefinitions::TOOL_GET_CART,
                ['cart_id' => $cartId],
                $ctx->shopDomain,
            );
        } catch (Throwable $e) {
            $this->logToolError('suggest_upsell.get_cart', $ctx, $e);

            return ToolResult::error('Could not read cart for upsell.');
        }

        $cart = CartMapper::fromCart($cartResult);
        if ($cart === null) {
            return ToolResult::error('Empty cart — nothing to upsell against.');
        }

        $cartItems = array_map(
            static fn (array $line): array => [
                'product_id' => $line['product_id'] ?? '',
                'quantity' => (int) ($line['quantity'] ?? 0),
            ],
            $cart->items,
        );

        $suggestions = $this->upsell->getUpsells($cartItems, $ctx->shopDomain, $cart->currency);
        $cartTotalMajor = $cart->subtotalMinorUnits !== null ? $cart->subtotalMinorUnits / 100 : 0.0;
        $gap = $this->upsell->getFreeShippingGap($cartTotalMajor, $ctx->shopDomain);
        $threshold = (float) config('sales.upsell.free_shipping_threshold', config('chatbot.default_free_ship_threshold', 50.00));

        $payload = [
            'upsells' => array_map(static fn ($dto) => $dto->toArray(), $suggestions),
            'free_shipping_gap' => $gap,
            'threshold' => $threshold,
        ];
        $this->emitter->emit('upsell_offer', $payload);

        $count = count($suggestions);

        return ToolResult::success(
            $count > 0 ? "Suggested {$count} upsells." : 'No upsell candidates available.',
            ['type' => 'upsell_offer'] + $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $mcpResult
     */
    private function handleProductList(array $mcpResult, ChatSessionContext $ctx): ToolResult
    {
        $dtos = ProductMapper::fromSearchResult($mcpResult, $ctx->shopDomain);
        $cards = array_map(static fn ($dto) => $dto->toMcpChunk(), $dtos);

        $payload = ['products' => $cards];
        $this->emitter->emit('products', $payload);

        $count = count($cards);

        return ToolResult::success(
            $count > 0 ? "Found {$count} products." : 'No products matched.',
            ['type' => 'products'] + $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $mcpResult
     */
    private function handleProductDetail(array $mcpResult): ToolResult
    {
        $dto = ProductMapper::fromGetProduct($mcpResult);
        if ($dto === null) {
            $this->emitter->emit('text', ['content' => "I couldn't find that product."]);

            return ToolResult::error('Product not found.');
        }

        $payload = ['product' => $dto->toArray()];
        $this->emitter->emit('product_detail', $payload);

        return ToolResult::success(
            "Loaded product detail for {$dto->title}.",
            ['type' => 'product_detail'] + $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $mcpResult
     */
    private function handleCart(array $mcpResult): ToolResult
    {
        $dto = CartMapper::fromCart($mcpResult);
        if ($dto === null) {
            return ToolResult::error('Cart not found.');
        }

        $payload = ['cart' => $dto->toArray()];
        $this->emitter->emit('cart_state', $payload);

        // Echo the actual cart_id back to the model so subsequent tool calls
        // (start_checkout, suggest_upsell, update_cart) can target the right
        // cart instead of inventing placeholders.
        return ToolResult::success(
            "Cart {$dto->id} now has {$dto->itemCount} item(s). Use this cart_id in any follow-up cart / checkout tool call.",
            ['type' => 'cart_state'] + $payload,
        );
    }

    /**
     * @param  array<string, mixed>  $mcpResult
     */
    private function handlePolicy(array $mcpResult): ToolResult
    {
        $mapped = PolicyMapper::fromAnswer($mcpResult);
        if ($mapped['answer'] === '') {
            $this->emitter->emit('text', ['content' => "I couldn't find anything on that in our policies."]);

            return ToolResult::error('No policy answer.');
        }

        $this->emitter->emit('policy_answer', $mapped);

        return ToolResult::success('Answered from store policy.', ['type' => 'policy_answer'] + $mapped);
    }

    private function emitAuthRequired(ChatSessionContext $ctx): ToolResult
    {
        $payload = [
            'reason' => 'customer_account',
            'oauth_start_url' => route('api.v1.ai.oauth.customer.start', [
                'session_id' => $ctx->sessionId,
                'shop_domain' => $ctx->shopDomain,
            ]),
        ];

        $this->emitter->emit('auth_required', $payload);

        return ToolResult::authRequired(
            'Customer is not signed in — the UI is showing the sign-in popup now.',
            ['type' => 'auth_required'] + $payload,
        );
    }

    private function resolveCustomerToken(string $sessionId): ?string
    {
        $row = AiCustomerSession::query()
            ->where('session_id', $sessionId)
            ->active()
            ->first();

        return $row?->customer_access_token;
    }

    /**
     * @param  array<string, mixed>  $args
     * @param  callable():array<string, mixed>  $call
     * @return array<string, mixed>
     */
    private function withCache(string $toolName, array $args, string $shopDomain, callable $call): array
    {
        $ttl = (int) (config("chatbot.mcp.cache_ttl_seconds.{$toolName}") ?? 0);
        if ($ttl <= 0) {
            return $call();
        }

        $hash = md5(json_encode($args, JSON_THROW_ON_ERROR) ?: '');
        $key = "ai:mcp:{$toolName}:{$shopDomain}:{$hash}";

        return Cache::remember($key, $ttl, $call);
    }

    private function withinRateLimit(string $sessionId): bool
    {
        $key = sprintf(self::RATE_LIMIT_KEY, $sessionId);
        $count = (int) Cache::increment($key);
        if ($count === 1) {
            Cache::put($key, 1, self::RATE_LIMIT_TTL);
        }

        return $count <= self::RATE_LIMIT_MAX;
    }

    /**
     * The LLM frequently strips the `gid://shopify/{Type}/` prefix from
     * Shopify global IDs even though we ask for the full GID. Re-attach the
     * prefix when the arg looks like a bare numeric id so the upstream MCP
     * doesn't reject the call with `Variable $id of type ID! was provided
     * invalid value`.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function normaliseStorefrontArgs(string $toolName, array $args): array
    {
        if ($toolName === ToolDefinitions::TOOL_GET_PRODUCT_DETAILS) {
            $args['product_id'] = $this->toGid('Product', $args['product_id'] ?? '');
        }

        if ($toolName === ToolDefinitions::TOOL_UPDATE_CART) {
            foreach (['add_items'] as $key) {
                if (! isset($args[$key]) || ! is_array($args[$key])) {
                    continue;
                }
                foreach ($args[$key] as $idx => $row) {
                    if (! is_array($row)) {
                        continue;
                    }
                    if (isset($row['product_variant_id'])) {
                        $args[$key][$idx]['product_variant_id'] = $this->toGid('ProductVariant', $row['product_variant_id']);
                    }
                }
            }
        }

        return $args;
    }

    private function toGid(string $type, mixed $value): string
    {
        $value = is_string($value) ? trim($value) : (string) $value;
        if ($value === '') {
            return $value;
        }
        if (str_starts_with($value, 'gid://')) {
            return $value;
        }
        if (ctype_digit($value)) {
            return "gid://shopify/{$type}/{$value}";
        }

        return $value;
    }

    private function logToolError(string $toolName, ChatSessionContext $ctx, Throwable $e): void
    {
        Log::channel('ai')->warning('tool.execute_failed', [
            'tool' => $toolName,
            'session_id' => $ctx->sessionId,
            'shop_domain' => $ctx->shopDomain,
            'exception' => $e::class,
            'message' => $e->getMessage(),
        ]);
    }
}
