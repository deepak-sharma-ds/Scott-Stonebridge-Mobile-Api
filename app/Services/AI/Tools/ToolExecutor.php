<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Contracts\Services\Sales\StoreKnowledgeServiceInterface;
use App\Contracts\Services\Sales\UpsellServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Chat\ProductRecommendationDTO;
use App\Exceptions\AI\AIServiceUnavailableException;
use App\Exceptions\AI\AuthRequiredException;
use App\Exceptions\AI\McpToolException;
use App\Models\AiCustomerSession;
use App\Services\AI\ChatSessionContext;
use App\Services\AI\MCP\CustomerAccountGraphClient;
use App\Services\AI\MCP\CustomerMcpClient;
use App\Services\AI\MCP\Mappers\CartMapper;
use App\Services\AI\MCP\Mappers\CustomerGraphOrderMapper;
use App\Services\AI\MCP\Mappers\OrderMapper;
use App\Services\AI\MCP\Mappers\PolicyMapper;
use App\Services\AI\MCP\Mappers\ProductMapper;
use App\Services\AI\MCP\StorefrontMcpClient;
use App\Services\AI\Streaming\ChunkEmitter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
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

    /**
     * Cache key for the per-session Shopify Storefront Cart GID. Shopify
     * carts expire roughly 10 days after last touch, so we keep ours 7 days
     * so a returning visitor in the same chat session can still mutate the
     * cart they built earlier without the model having to memorise the GID.
     */
    private const SESSION_CART_KEY = 'ai:session:%s:cart_id';

    private const SESSION_CART_TTL = 604800;

    private ?StorefrontApiClientInterface $storefrontApi;

    private ?CustomerAccountGraphClient $customerGraph;

    public function __construct(
        private readonly StorefrontMcpClient $storefront,
        private readonly CustomerMcpClient $customer,
        private readonly ChunkEmitter $emitter,
        private readonly UpsellServiceInterface $upsell,
        ?StorefrontApiClientInterface $storefrontApi = null,
        ?CustomerAccountGraphClient $customerGraph = null,
    ) {
        $this->storefrontApi = $storefrontApi;
        $this->customerGraph = $customerGraph;
    }

    /**
     * Lazily resolve the Customer Account GraphQL client. Stays optional in
     * the constructor so older service-container bindings (4 / 5 arg signatures
     * + bind helpers in tests) keep working without modification.
     */
    private function customerGraph(): CustomerAccountGraphClient
    {
        return $this->customerGraph ??= app(CustomerAccountGraphClient::class);
    }

    /**
     * Lazily resolve the Storefront API client from the container so older
     * call sites that build ToolExecutor with four arguments keep working.
     */
    private function storefrontApi(): StorefrontApiClientInterface
    {
        return $this->storefrontApi ??= app(StorefrontApiClientInterface::class);
    }

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
        // Hydrate the cart_id from the session cache when the inbound request
        // didn't carry one. Without this the model has to remember the GID
        // across turns and Shopify mints a fresh empty cart on every miss.
        if ($ctx->cartId === null) {
            $cached = $this->recallSessionCartId($ctx->sessionId);
            if ($cached !== null) {
                $ctx = $ctx->withCartId($cached);
            }
        }

        $args = $this->normaliseStorefrontArgs($toolName, $args);

        // Inject the known cart_id when the model omitted it on update_cart /
        // get_cart so we keep mutating the SAME cart instead of spawning a new
        // empty one on every turn.
        if ($ctx->cartId !== null
            && in_array($toolName, [ToolDefinitions::TOOL_UPDATE_CART, ToolDefinitions::TOOL_GET_CART], true)
            && empty($args['cart_id'])
        ) {
            $args['cart_id'] = $ctx->cartId;
        }

        // Product detail: Shopify MCP `get_product_details` only returns ONE
        // variant (`selectedOrFirstAvailableVariant`). Pull the full product —
        // every variant, per-variant image, and option groups — from the
        // Storefront GraphQL API instead. Fall back to the MCP path on any
        // failure so a GraphQL/credential hiccup still yields a card.
        if ($toolName === ToolDefinitions::TOOL_GET_PRODUCT_DETAILS) {
            $detail = $this->handleProductDetailViaStorefront($args, $ctx);
            if ($detail !== null) {
                return $detail;
            }

            // Fallback prep: the MCP call needs a GID, not a handle/slug.
            $pid = (string) ($args['product_id'] ?? '');
            if ($pid !== '' && ! str_starts_with($pid, 'gid://')) {
                $resolved = $this->resolveProductIdFromQuery($pid, $ctx);
                if ($resolved !== null) {
                    $args['product_id'] = $resolved;
                }
            }
        }

        // Shopify's `search_catalog` MCP ignores the query and returns the same
        // default catalogue regardless of keyword — useless for "show me
        // crystals" style asks. Route catalogue search through the Storefront
        // GraphQL API instead (collection-aware + real text search).
        if ($toolName === ToolDefinitions::TOOL_SEARCH_CATALOG) {
            return $this->handleCatalogSearch($args, $ctx);
        }

        // Quantity changes (`update_items`) and removals (`remove_line_ids`)
        // key off the Shopify CartLine GID, not the variant id. The model (and
        // the widget) frequently sends a ProductVariant id — translate it to
        // the matching cart-line id by reading the live cart first.
        if ($toolName === ToolDefinitions::TOOL_UPDATE_CART) {
            $args = $this->resolveCartLineRefs($args, $ctx);
        }

        $isCartTool = in_array($toolName, [ToolDefinitions::TOOL_GET_CART, ToolDefinitions::TOOL_UPDATE_CART], true);
        $result = $this->withCache(
            $toolName,
            $args,
            $ctx->shopDomain,
            fn (): array => $isCartTool
                ? $this->callCartToolWithRecovery($toolName, $args, $ctx)
                : $this->storefront->callTool($toolName, $args, $ctx->shopDomain),
        );

        return match ($toolName) {
            ToolDefinitions::TOOL_GET_PRODUCT_DETAILS => $this->handleProductDetail($result),
            ToolDefinitions::TOOL_GET_CART, ToolDefinitions::TOOL_UPDATE_CART => $this->handleCart($result, $ctx),
            ToolDefinitions::TOOL_SEARCH_POLICIES => $this->handlePolicy($result, (string) ($args['query'] ?? ''), $ctx),
            default => ToolResult::error("Unhandled storefront tool: {$toolName}"),
        };
    }

    /**
     * Catalogue search via Storefront GraphQL. Maps a category-style query to
     * a known collection handle (curated, relevant) and falls back to the
     * Storefront product text-search for free-text queries.
     *
     * @param  array<string, mixed>  $args
     */
    private function handleCatalogSearch(array $args, ChatSessionContext $ctx): ToolResult
    {
        $query = trim((string) ($args['query'] ?? ''));
        $limit = (int) ($args['limit'] ?? 0);
        $limit = $limit >= 1 && $limit <= 12 ? $limit : 10;

        $cacheKey = 'ai:catalog:'.md5($ctx->shopDomain.'|'.strtolower($query).'|'.$limit);
        $ttl = (int) (config('chatbot.mcp.cache_ttl_seconds.search_catalog') ?? 120);

        try {
            $nodes = Cache::remember($cacheKey, $ttl, function () use ($query, $limit): array {
                $handle = $this->mapQueryToCollection($query);

                if ($handle !== null) {
                    $resp = $this->storefrontApi()->query('storefront/collection/collection_products', [
                        'handle' => $handle,
                        'limit' => $limit,
                    ]);
                    $edges = $resp['data']['collectionByHandle']['products']['edges'] ?? [];
                } else {
                    $resp = $this->storefrontApi()->query('storefront/products/get_all_products', [
                        'limit' => $limit,
                        'query' => $query !== '' ? $query : '*',
                    ]);
                    $edges = $resp['data']['products']['edges'] ?? [];
                }

                return array_values(array_filter(array_map(
                    static fn ($e) => is_array($e) ? ($e['node'] ?? null) : null,
                    is_array($edges) ? $edges : [],
                )));
            });
        } catch (Throwable $e) {
            $this->logToolError('search_catalog.storefront', $ctx, $e);

            return ToolResult::error('Catalogue search failed.');
        }

        $cards = [];
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            $cards[] = ProductRecommendationDTO::fromShopifyNode($node, $ctx->shopDomain)->toMcpChunk();
        }

        $payload = ['products' => $cards];
        $this->emitter->emit('products', $payload);

        $count = count($cards);

        return ToolResult::success(
            $count > 0 ? "Found {$count} products for \"{$query}\"." : "No products matched \"{$query}\".",
            ['type' => 'products'] + $payload,
        );
    }

    /**
     * Map a free-text query to a curated Shopify collection handle. Keyword
     * order matters — first hit wins. Returns null when no category fires so
     * the caller falls back to text search.
     */
    private function mapQueryToCollection(string $query): ?string
    {
        $q = strtolower($query);

        // handle => keyword list (live collections on the Scott Stonebridge store)
        $map = [
            'email-readings' => ['email reading', 'email readings'],
            'readings' => ['reading', 'tarot', 'psychic reading', 'clairvoyant', 'fortune', 'spirit reading'],
            'meditations' => ['meditation', 'guided meditation', 'sleep meditation', 'relaxation'],
            'crystals' => ['crystal', 'gemstone', 'quartz', 'amethyst', 'healing stone', 'chakra stone'],
            'candles' => ['candle', 'burner', 'incense', 'wax'],
            'oils' => ['oil', 'diffuser', 'essential oil', 'aromatherapy'],
            'bracelets' => ['bracelet', 'bangle'],
            'necklaces' => ['necklace', 'pendant', 'choker'],
            'gift-cards' => ['gift card', 'gift voucher', 'gift certificate'],
        ];

        foreach ($map as $handle => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($q, $kw)) {
                    return $handle;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $args
     */
    private function executeCustomerMcp(string $toolName, array $args, ChatSessionContext $ctx): ToolResult
    {
        $token = $ctx->customerAccessToken ?? $this->resolveCustomerToken($ctx->sessionId, $ctx->shopDomain);
        if ($token === null) {
            return $this->emitAuthRequired($ctx);
        }

        try {
            $result = $this->customer->callTool($toolName, $args, $ctx->shopDomain, $token);
        } catch (AuthRequiredException $e) {
            // MCP rejected a valid token. Fall back to the Customer Account
            // GraphQL API, which uses the SAME `customer-account-api:full`
            // scope but the GraphQL surface (always enabled on stock Headless
            // apps). Bypassing MCP keeps the order flow working even when
            // Shopify hasn't provisioned MCP for this app.
            Log::channel('ai')->info('tool.customer_mcp_rejected_falling_back_to_graphql', [
                'session_id' => $ctx->sessionId,
                'shop_domain' => $ctx->shopDomain,
                'tool' => $toolName,
            ]);

            return $this->executeCustomerOrderViaGraph($toolName, $args, $ctx, $token);
        }

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
     * Fallback: query Customer Account GraphQL when the MCP endpoint rejected
     * a valid token. Maps the GraphQL response onto the same OrderTrackingDTO
     * so the downstream `order_tracking` chunk stays identical.
     *
     * @param  array<string, mixed>  $args
     */
    private function executeCustomerOrderViaGraph(string $toolName, array $args, ChatSessionContext $ctx, string $token): ToolResult
    {
        try {
            $data = $this->customerGraph()->query(
                $ctx->shopDomain,
                $token,
                $this->customerOrderQuery($toolName),
                $this->customerOrderVariables($toolName, $args),
            );
        } catch (AuthRequiredException $e) {
            // Token rejected by GraphQL too — genuine auth state mismatch.
            // Emit a soft text instead of re-opening the popup (which would
            // re-trigger the loop) and let the user retry from the chat UI.
            Log::channel('ai')->warning('tool.customer_graph_rejected_valid_token', [
                'session_id' => $ctx->sessionId,
                'shop_domain' => $ctx->shopDomain,
                'tool' => $toolName,
            ]);

            $this->emitter->emit('text', [
                'content' => "You're signed in, but I can't reach the live order service right now. "
                    .'Please check your order history in your account, or try again in a few minutes.',
            ]);

            return ToolResult::error('Customer Account GraphQL rejected a valid token.');
        } catch (Throwable $e) {
            $this->logToolError($toolName, $ctx, $e);

            $this->emitter->emit('text', [
                'content' => "I couldn't reach the order service just now. Please try again in a moment.",
            ]);

            return ToolResult::error("Customer GraphQL fallback failed: {$e->getMessage()}");
        }

        $dto = $toolName === ToolDefinitions::TOOL_GET_ORDER_STATUS
            ? CustomerGraphOrderMapper::fromOrderNode($this->extractOrderNodeFromGraph($data))
            : CustomerGraphOrderMapper::fromMostRecent($data);

        if ($dto === null) {
            $this->emitter->emit('text', ['content' => "I couldn't find an order matching that request."]);

            return ToolResult::error('Order not found via GraphQL fallback.');
        }

        $payload = ['order_tracking' => $dto->toArray()];
        $this->emitter->emit('order_tracking', $payload);

        return ToolResult::success(
            "Order {$dto->orderNumber} status: {$dto->status}.",
            ['type' => 'order_tracking'] + $payload,
        );
    }

    /**
     * Customer Account API queries — minimal field set matching the MCP shape
     * the OrderMapper used to consume. Two variants:
     *   - most recent: no args needed
     *   - by name: requires `name:` filter, used for explicit order lookups
     */
    private function customerOrderQuery(string $toolName): string
    {
        // Customer Account API: `Order.fulfillments` IS a `FulfillmentConnection`
        // (despite the Admin API exposing it as a flat list) so we MUST
        // paginate with `first:` + `edges { node {} }`. Confirmed live via
        // `Field 'estimatedDeliveryAt' doesn't exist on type 'FulfillmentConnection'`.
        // `trackingInformation` on the Fulfillment node stays a flat list.
        $node = <<<'GRAPHQL'
        id
        name
        number
        processedAt
        fulfillmentStatus
        financialStatus
        shippingAddress { city }
        fulfillments(first: 5) {
          edges {
            node {
              estimatedDeliveryAt
              trackingInformation { number url company }
            }
          }
        }
        GRAPHQL;

        if ($toolName === ToolDefinitions::TOOL_GET_ORDER_STATUS) {
            return <<<GRAPHQL
            query OrderByName(\$query: String!) {
              customer {
                orders(first: 1, query: \$query) {
                  edges { node { {$node} } }
                }
              }
            }
            GRAPHQL;
        }

        return <<<GRAPHQL
        query MostRecentOrder {
          customer {
            orders(first: 1, sortKey: PROCESSED_AT, reverse: true) {
              edges { node { {$node} } }
            }
          }
        }
        GRAPHQL;
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function customerOrderVariables(string $toolName, array $args): array
    {
        if ($toolName !== ToolDefinitions::TOOL_GET_ORDER_STATUS) {
            return [];
        }

        $orderId = (string) ($args['order_id'] ?? $args['order_number'] ?? $args['name'] ?? '');
        $orderId = ltrim($orderId, '#');

        return ['query' => $orderId === '' ? '' : "name:#{$orderId}"];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function extractOrderNodeFromGraph(array $data): array
    {
        $node = $data['customer']['orders']['edges'][0]['node'] ?? null;

        return is_array($node) ? $node : [];
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
            ToolDefinitions::TOOL_SEARCH_KNOWLEDGE => $this->handleSearchKnowledge($args, $ctx),
            default => ToolResult::error("Unknown internal tool: {$toolName}"),
        };
    }

    /**
     * `search_knowledge_base` — local knowledge lookup. Calls the hybrid
     * keyword + semantic ranker on the StoreKnowledgeService so the LLM
     * can recover when the auto-injected STORE KNOWLEDGE block missed a
     * relevant row. Results are returned to the model verbatim (no SSE
     * emission — the model paraphrases them into the reply).
     *
     * @param  array<string, mixed>  $args
     */
    private function handleSearchKnowledge(array $args, ChatSessionContext $ctx): ToolResult
    {
        $query = trim((string) ($args['query'] ?? ''));
        if ($query === '') {
            return ToolResult::error('search_knowledge_base requires query.');
        }

        $contentTypes = isset($args['content_types']) && is_array($args['content_types'])
            ? array_values(array_filter(array_map(
                static fn ($t): string => is_string($t) ? trim($t) : '',
                $args['content_types'],
            ), static fn (string $t): bool => $t !== ''))
            : null;

        $limit = isset($args['limit']) ? (int) $args['limit'] : 5;
        $limit = max(1, min($limit, 8));

        $shop = (string) ($ctx->shopDomain ?? config('shopify.store_domain'));
        if ($shop === '') {
            return ToolResult::error('search_knowledge_base requires shop context.');
        }

        try {
            /** @var StoreKnowledgeServiceInterface $knowledge */
            $knowledge = app(StoreKnowledgeServiceInterface::class);
            $results = $knowledge->searchForTool($shop, $query, $contentTypes, $limit);
        } catch (Throwable $e) {
            $this->logToolError('search_knowledge_base', $ctx, $e);

            return ToolResult::error('Knowledge search failed.');
        }

        return ToolResult::success(
            'Knowledge search complete.',
            [
                'type' => 'knowledge_search',
                'query' => $query,
                'results' => $results,
            ],
        );
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

        $payload = [
            'upsells' => array_map(static fn ($dto) => $dto->toArray(), $suggestions),
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
     * Load the FULL product (all variants, per-variant images, option groups)
     * from the Storefront GraphQL API. Returns null — so the caller falls back
     * to the single-variant MCP path — when the product can't be resolved or
     * the API errors.
     *
     * @param  array<string, mixed>  $args
     */
    private function handleProductDetailViaStorefront(array $args, ChatSessionContext $ctx): ?ToolResult
    {
        $vars = $this->resolveProductDetailVars($args, $ctx);
        if ($vars === null) {
            return null;
        }

        try {
            $resp = $this->storefrontApi()->query('storefront/products/get_product_detail_for_chat', $vars);
        } catch (Throwable $e) {
            $this->logToolError('get_product_details.storefront', $ctx, $e);

            return null;
        }

        $node = $resp['data']['product'] ?? null;
        if (! is_array($node)) {
            return null;
        }

        $dto = ProductMapper::fromStorefrontDetailNode($node);
        if ($dto === null) {
            return null;
        }

        $payload = ['product' => $dto->toArray()];
        $this->emitter->emit('product_detail', $payload);

        return ToolResult::success(
            "Loaded product detail for {$dto->title}.",
            ['type' => 'product_detail'] + $payload,
        );
    }

    /**
     * Resolve the Storefront `product(handle:|id:)` variables from whatever the
     * model passed (handle, Product GID, bare numeric id, slug, or free text).
     *
     * @param  array<string, mixed>  $args
     * @return array{handle:string}|array{id:string}|null
     */
    private function resolveProductDetailVars(array $args, ChatSessionContext $ctx): ?array
    {
        $handle = trim((string) ($args['handle'] ?? ''));
        if ($handle !== '') {
            return ['handle' => $handle];
        }

        $pid = trim((string) ($args['product_id'] ?? ''));
        if ($pid === '') {
            return null;
        }

        if (str_starts_with($pid, 'gid://shopify/Product/')) {
            return ['id' => $pid];
        }
        if (ctype_digit($pid)) {
            return ['id' => $this->toGid('Product', $pid)];
        }
        // A bare handle/slug: lowercase alphanumerics + dashes, no spaces.
        if (preg_match('/^[a-z0-9][a-z0-9\-]*$/', $pid) === 1) {
            return ['handle' => $pid];
        }

        // Free text / title — resolve to a GID via the Storefront search.
        $resolved = $this->resolveProductIdFromQuery($pid, $ctx);

        return $resolved !== null ? ['id' => $resolved] : null;
    }

    /**
     * @param  array<string, mixed>  $mcpResult
     */
    private function handleCart(array $mcpResult, ChatSessionContext $ctx): ToolResult
    {
        $dto = CartMapper::fromCart($mcpResult);
        if ($dto === null) {
            return ToolResult::error('Cart not found.');
        }

        // Persist the Storefront cart GID for this chat session so the next
        // turn can mutate the same cart even when the widget forgot to send
        // it back. Without this, every remove / quantity-change call risks
        // landing on a brand new empty cart and Shopify rejecting the line ref.
        $this->rememberSessionCartId($ctx->sessionId, $dto->id);

        $cart = $dto->toArray();
        // Shopify's MCP cart omits line imagery — backfill from the Storefront
        // API so the widget's cart view can render product thumbnails.
        $cart['items'] = $this->attachCartLineImages($cart['items'], $ctx);
        $payload = ['cart' => $cart];
        $this->emitter->emit('cart_state', $payload);

        // Echo the cart_id AND a line-id ↔ title map so the model removes /
        // changes quantity using the correct CartLine GID (not the variant id).
        $lineHints = [];
        foreach ($cart['items'] as $line) {
            if (! empty($line['id'])) {
                $lineHints[] = "{$line['title']} → line {$line['id']} (qty {$line['quantity']})";
            }
        }
        $hint = $lineHints === [] ? '' : ' Lines: '.implode('; ', $lineHints).'. Use the line id for update_items/remove_line_ids.';

        return ToolResult::success(
            "Cart {$dto->id} now has {$dto->itemCount} item(s). Use this cart_id in any follow-up cart / checkout tool call.".$hint,
            ['type' => 'cart_state'] + $payload,
        );
    }

    /**
     * Translate variant / bare ids the model passed in `update_items[].id` or
     * `remove_line_ids` into the real Shopify CartLine GIDs by reading the live
     * cart. Leaves values already shaped like a CartLine GID untouched.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function resolveCartLineRefs(array $args, ChatSessionContext $ctx): array
    {
        $hasUpdate = isset($args['update_items']) && is_array($args['update_items']);
        $hasRemove = isset($args['remove_line_ids']) && is_array($args['remove_line_ids']);
        if (! $hasUpdate && ! $hasRemove) {
            return $args;
        }

        $cartId = (string) ($args['cart_id'] ?? $ctx->cartId ?? '');
        if (! str_starts_with($cartId, 'gid://shopify/Cart/')) {
            return $args;
        }

        // ALWAYS re-read the live cart — even when the ref already LOOKS like a
        // CartLine GID. Shopify re-keys cart lines on every mutation, so a line
        // id the model echoed from an earlier cart_state is frequently STALE
        // after an intervening add/remove. Forwarding a stale line id makes
        // update_cart fail with "Invalid global id" (surfaced to the user as
        // "I had trouble updating the quantity"). Validating every ref against
        // the current cart is the only reliable option.
        try {
            $cart = $this->storefront->callTool(ToolDefinitions::TOOL_GET_CART, ['cart_id' => $cartId], $ctx->shopDomain);
        } catch (Throwable $e) {
            $this->logToolError('update_cart.resolve_lines', $ctx, $e);

            return $args;
        }

        $dto = CartMapper::fromCart($cart);
        if ($dto === null || $dto->items === []) {
            return $args;
        }

        $map = $this->buildVariantToLineMap($cart);

        // Set of CartLine GIDs that exist in the cart RIGHT NOW, plus the lone
        // line id when the cart holds exactly one item (lets us re-point a
        // stale/ambiguous ref — the user can only mean that single line).
        $validLineIds = [];
        foreach ($dto->items as $line) {
            $lineId = (string) ($line['id'] ?? '');
            if ($lineId !== '') {
                $validLineIds[$lineId] = true;
            }
        }
        $onlyLineId = count($dto->items) === 1 ? ((string) ($dto->items[0]['id'] ?? '') ?: null) : null;

        if ($hasRemove) {
            $args['remove_line_ids'] = array_values(array_filter(array_map(
                fn ($ref): string => $this->resolveLineRef((string) $ref, $map, $validLineIds, $onlyLineId),
                $args['remove_line_ids'],
            ), static fn (string $ref): bool => self::isCartLineGid($ref)));
        }
        if ($hasUpdate) {
            foreach ($args['update_items'] as $idx => $row) {
                if (is_array($row) && isset($row['id'])) {
                    $args['update_items'][$idx]['id'] = $this->resolveLineRef((string) $row['id'], $map, $validLineIds, $onlyLineId);
                }
            }
            // Drop rows that still didn't resolve to a CURRENT line — sending
            // one guarantees a Shopify rejection the model can't recover from.
            $args['update_items'] = array_values(array_filter(
                $args['update_items'],
                static fn ($row): bool => is_array($row) && self::isCartLineGid((string) ($row['id'] ?? '')),
            ));
        }

        return $args;
    }

    /**
     * Resolve a single line ref (CartLine GID, variant GID, or bare numeric)
     * to a CartLine GID that EXISTS in the current cart. Returns '' when it
     * cannot be resolved so the caller drops it.
     *
     * @param  array<string, string>  $map  variant id → current line id
     * @param  array<string, bool>  $validLineIds  current line ids
     */
    private function resolveLineRef(string $ref, array $map, array $validLineIds, ?string $onlyLineId): string
    {
        // Already a line that exists in the cart right now.
        if (self::isCartLineGid($ref) && isset($validLineIds[$ref])) {
            return $ref;
        }

        // Variant GID / bare numeric → current line.
        $mapped = self::mapToLineId($ref, $map);
        if (self::isCartLineGid($mapped) && isset($validLineIds[$mapped])) {
            return $mapped;
        }

        // Stale line id or unresolved, but the cart holds a single line — the
        // user can only mean that one, so re-point the op onto it.
        if ($onlyLineId !== null) {
            return $onlyLineId;
        }

        return '';
    }

    /**
     * Map both variant GIDs and bare variant numerics → CartLine GID.
     *
     * @param  array<string, mixed>  $cartResult
     * @return array<string, string>
     */
    private function buildVariantToLineMap(array $cartResult): array
    {
        $dto = CartMapper::fromCart($cartResult);
        if ($dto === null) {
            return [];
        }

        $map = [];
        foreach ($dto->items as $line) {
            $lineId = $line['id'] ?? null;
            $variantId = $line['variant_id'] ?? null;
            if (! is_string($lineId) || $lineId === '' || ! is_string($variantId) || $variantId === '') {
                continue;
            }
            $map[$variantId] = $lineId;
            // Also key on the bare numeric tail so a stripped id still resolves.
            if (preg_match('~/(\d+)$~', $variantId, $m) === 1) {
                $map[$m[1]] = $lineId;
            }
        }

        return $map;
    }

    /**
     * @param  array<string, string>  $map
     */
    private static function mapToLineId(string $ref, array $map): string
    {
        if (self::isCartLineGid($ref)) {
            return $ref;
        }

        if (isset($map[$ref])) {
            return $map[$ref];
        }

        // The Shopify ajax cart key format is `<variant_id>:<line_token>`;
        // strip the trailing token so a bare-variant-numeric lookup wins.
        if (str_contains($ref, ':') && ! str_starts_with($ref, 'gid://')) {
            $head = strstr($ref, ':', true);
            if (is_string($head) && isset($map[$head])) {
                return $map[$head];
            }
        }

        // Tail numeric on a stray GID type (e.g. ProductVariant GID) so the
        // map's bare-numeric key still resolves the line.
        if (preg_match('~/(\d+)(?:[:?].*)?$~', $ref, $m) === 1 && isset($map[$m[1]])) {
            return $map[$m[1]];
        }

        return $ref;
    }

    private static function isCartLineGid(string $value): bool
    {
        return str_starts_with($value, 'gid://shopify/CartLine/');
    }

    /**
     * Call a Storefront cart tool, self-healing when the cart_id we injected
     * (cached from a previous turn, or echoed by the model) is dead at Shopify.
     *
     * Shopify carts expire / get consumed at checkout, so a stale cached GID
     * makes EVERY add fail with "cart not found" — and because the cache was
     * never cleared, the session stayed wedged ("I don't have a cart ID … technical
     * issue"). On a cart-specific error we forget the cached GID and, for an add,
     * retry once with cart_id stripped so Shopify mints a fresh cart.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function callCartToolWithRecovery(string $toolName, array $args, ChatSessionContext $ctx): array
    {
        try {
            return $this->storefront->callTool($toolName, $args, $ctx->shopDomain);
        } catch (McpToolException $e) {
            if (! $this->isStaleCartError($e->getMessage())) {
                throw $e;
            }

            // The cart_id we carried is dead — never reuse it again.
            $this->forgetSessionCartId($ctx->sessionId);

            $hasAdd = isset($args['add_items']) && is_array($args['add_items']) && $args['add_items'] !== [];
            if ($toolName === ToolDefinitions::TOOL_UPDATE_CART && $hasAdd) {
                Log::channel('ai')->info('cart.stale_id_recovered', [
                    'session_id' => $ctx->sessionId,
                    'shop_domain' => $ctx->shopDomain,
                ]);

                // Drop the dead cart_id + any line ops that referenced its now
                // gone lines, then re-add on a fresh cart Shopify mints for us.
                unset($args['cart_id'], $args['update_items'], $args['remove_line_ids']);

                return $this->storefront->callTool($toolName, $args, $ctx->shopDomain);
            }

            // get_cart / quantity-change / removal on a dead cart can't be
            // rescued without the cart — surface so the model re-syncs cleanly.
            throw $e;
        }
    }

    /**
     * Heuristic: does this MCP error mean the cart_id is gone / invalid (vs an
     * unrelated failure we must not swallow)? Requires a cart reference plus a
     * "not found / invalid" signal so we don't nuke the cache on every error.
     */
    private function isStaleCartError(string $message): bool
    {
        $m = strtolower($message);
        if (! str_contains($m, 'cart')) {
            return false;
        }

        foreach (['not found', 'no longer', 'does not exist', "doesn't exist", 'invalid', 'expired', 'could not find', "couldn't find", 'not exist', 'unable to find'] as $needle) {
            if (str_contains($m, $needle)) {
                return true;
            }
        }

        // Shopify rejects a malformed/foreign Cart GID with this phrasing.
        return str_contains($m, 'invalid global id') || str_contains($m, 'invalid id');
    }

    /**
     * Cache the active Shopify Storefront Cart GID for the chat session so
     * future tool calls in the same session can reuse it without relying on
     * the LLM to echo it back from the previous turn's message.
     */
    private function rememberSessionCartId(string $sessionId, string $cartId): void
    {
        if ($sessionId === '' || ! str_starts_with($cartId, 'gid://shopify/Cart/')) {
            return;
        }

        Cache::put(sprintf(self::SESSION_CART_KEY, $sessionId), $cartId, self::SESSION_CART_TTL);
    }

    private function recallSessionCartId(string $sessionId): ?string
    {
        if ($sessionId === '') {
            return null;
        }

        $value = Cache::get(sprintf(self::SESSION_CART_KEY, $sessionId));

        return is_string($value) && str_starts_with($value, 'gid://shopify/Cart/') ? $value : null;
    }

    /**
     * Drop the cached session cart GID — call when Shopify reports the cart
     * has been consumed / abandoned and the next add should mint a new one.
     */
    private function forgetSessionCartId(string $sessionId): void
    {
        if ($sessionId === '') {
            return;
        }

        Cache::forget(sprintf(self::SESSION_CART_KEY, $sessionId));
    }

    /**
     * Backfill `image` on each cart line from the Storefront API (the MCP cart
     * payload has no imagery). Best-effort: returns lines unchanged on failure.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private function attachCartLineImages(array $items, ChatSessionContext $ctx): array
    {
        $variantIds = [];
        foreach ($items as $line) {
            if (empty($line['image']) && ! empty($line['variant_id'])) {
                $variantIds[] = (string) $line['variant_id'];
            }
        }
        $variantIds = array_values(array_unique($variantIds));
        if ($variantIds === []) {
            return $items;
        }

        try {
            $resp = $this->storefrontApi()->query('storefront/products/get_variant_images', ['ids' => $variantIds]);
        } catch (Throwable $e) {
            $this->logToolError('cart.variant_images', $ctx, $e);

            return $items;
        }

        $byVariant = [];
        foreach ((array) ($resp['data']['nodes'] ?? []) as $node) {
            if (! is_array($node) || empty($node['id'])) {
                continue;
            }
            $url = $node['image']['url'] ?? $node['product']['featuredImage']['url'] ?? null;
            if (is_string($url) && $url !== '') {
                $byVariant[(string) $node['id']] = $url;
            }
        }

        foreach ($items as $idx => $line) {
            if (empty($line['image']) && ! empty($line['variant_id']) && isset($byVariant[$line['variant_id']])) {
                $items[$idx]['image'] = $byVariant[$line['variant_id']];
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $mcpResult
     */
    private function handlePolicy(array $mcpResult, string $query, ChatSessionContext $ctx): ToolResult
    {
        $mapped = PolicyMapper::fromAnswer($mcpResult);

        // Shopify's `search_shop_policies_and_faqs` only returns the FAQ Q&A
        // dataset (returns, shipping). Full policy bodies — privacy, terms,
        // refund, shipping — and their many sub-sections (data deletion,
        // cookies, retention, cancellations…) live behind the Storefront
        // GraphQL `shop.{policy}` fields. Search ALL of them for the section
        // that answers the user's specific question.
        $section = $this->findPolicySection($query, $ctx->shopDomain);

        if ($section !== null) {
            $citations = array_merge(
                [['title' => $section['title'], 'url' => $section['url']]],
                array_values(array_filter($mapped['citations'], fn (array $c): bool => ($c['url'] ?? '') !== '')),
            );

            // The chunk surfaces the focused excerpt (what the user asked);
            // the frontend "Read more" links out to the full policy page.
            $chunkAnswer = sprintf("**%s**\n%s", $section['title'], $section['excerpt']);
            if ($mapped['answer'] !== '') {
                $chunkAnswer .= "\n\n---\n\n".$mapped['answer'];
            }
            $mapped = ['answer' => $chunkAnswer, 'citations' => $citations];

            $this->emitter->emit('policy_answer', $mapped);

            // Feed the actual policy text back to the model so its streamed
            // reply quotes the right section instead of "I couldn't find it".
            $forAi = sprintf(
                "Store policy excerpt from \"%s\" relevant to the question \"%s\":\n\n%s\n\nAnswer the customer using ONLY this text. If it doesn't cover their question, say so and offer the full policy link: %s",
                $section['title'],
                $query,
                $section['excerpt'],
                $section['url'],
            );

            return ToolResult::success($forAi, ['type' => 'policy_answer'] + $mapped);
        }

        if ($mapped['answer'] === '') {
            $this->emitter->emit('text', ['content' => "I couldn't find anything on that in our policies."]);

            return ToolResult::error('No policy answer.');
        }

        $this->emitter->emit('policy_answer', $mapped);

        return ToolResult::success($mapped['answer'], ['type' => 'policy_answer'] + $mapped);
    }

    /**
     * Search EVERY Storefront policy body for the passage most relevant to the
     * query and return a focused excerpt + the parent policy's title/url.
     *
     * Scores each text block by how many query tokens it contains, then
     * returns the densest block plus its neighbours (so sub-policies like
     * "account deletion" inside the Privacy Policy surface correctly).
     *
     * @return array{title:string,url:string,excerpt:string}|null
     */
    private function findPolicySection(string $query, string $shopDomain): ?array
    {
        $tokens = $this->queryTokens($query);
        $policies = $this->fetchStorefrontPolicies($shopDomain);
        if ($policies === []) {
            return null;
        }

        $best = null; // [score, policyKey, blockIndex, blocks[]]
        foreach ($policies as $key => $policy) {
            if (empty($policy['body'])) {
                continue;
            }
            $text = $this->htmlToText((string) $policy['body']);
            $blocks = array_values(array_filter(
                preg_split('/\n{2,}/', $text) ?: [],
                static fn (string $b): bool => trim($b) !== '',
            ));

            foreach ($blocks as $i => $block) {
                $score = $this->scoreBlock(strtolower($block), $tokens);
                if ($score > 0 && ($best === null || $score > $best[0])) {
                    $best = [$score, $key, $i, $blocks, $policy];
                }
            }
        }

        if ($best === null) {
            // No token hit anywhere — fall back to a top-level keyword bucket
            // so "privacy"/"terms"/"refund"/"shipping" still return the intro.
            return $this->topLevelPolicyFallback($query, $policies);
        }

        [$score, $key, $idx, $blocks, $policy] = $best;

        // Stitch the matched block with one neighbour on each side, cap length.
        $start = max(0, $idx - 1);
        $slice = array_slice($blocks, $start, 3);
        $excerpt = trim(implode("\n\n", $slice));
        if (mb_strlen($excerpt) > 1600) {
            $excerpt = mb_substr($excerpt, 0, 1600).'…';
        }

        return [
            'title' => (string) ($policy['title'] ?? ''),
            'url' => (string) ($policy['url'] ?? ''),
            'excerpt' => $excerpt,
        ];
    }

    /**
     * @param  list<string>  $tokens
     */
    private function scoreBlock(string $blockLower, array $tokens): int
    {
        $score = 0;
        foreach ($tokens as $t) {
            $score += substr_count($blockLower, $t);
        }

        return $score;
    }

    /**
     * @return list<string>
     */
    private function queryTokens(string $query): array
    {
        $stop = [
            'the', 'of', 'is', 'a', 'an', 'to', 'for', 'and', 'or', 'in', 'on', 'do', 'does',
            'you', 'your', 'this', 'that', 'what', 'whats', 'how', 'can', 'i', 'me', 'my',
            'tell', 'about', 'shop', 'store', 'policy', 'policies', 'please', 'have', 'with',
            'are', 'there', 'any', 'show', 'give', 'it', 'its', 'their',
        ];

        $words = preg_split('/[^a-z0-9]+/', strtolower($query)) ?: [];

        return array_values(array_unique(array_filter(
            $words,
            static fn (string $w): bool => mb_strlen($w) >= 3 && ! in_array($w, $stop, true),
        )));
    }

    /**
     * @param  array<string, array<string, mixed>>  $policies
     * @return array{title:string,url:string,excerpt:string}|null
     */
    private function topLevelPolicyFallback(string $query, array $policies): ?array
    {
        $q = strtolower($query);
        $map = [
            'privacyPolicy' => ['privacy', 'data', 'cookie', 'gdpr', 'personal', 'account', 'delete'],
            'termsOfService' => ['terms', 'tos', 'service', 'agreement', 'condition'],
            'refundPolicy' => ['refund', 'return', 'money back', 'cancel', 'exchange'],
            'shippingPolicy' => ['shipping', 'delivery', 'dispatch', 'tracking', 'arrive'],
        ];

        foreach ($map as $field => $keywords) {
            foreach ($keywords as $kw) {
                if (str_contains($q, $kw) && ! empty($policies[$field]['body'])) {
                    $text = $this->htmlToText((string) $policies[$field]['body']);
                    $excerpt = mb_strlen($text) > 1200 ? mb_substr($text, 0, 1200).'…' : $text;

                    return [
                        'title' => (string) ($policies[$field]['title'] ?? ''),
                        'url' => (string) ($policies[$field]['url'] ?? ''),
                        'excerpt' => $excerpt,
                    ];
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function fetchStorefrontPolicies(string $shopDomain): array
    {
        $key = "ai:policy_bodies:{$shopDomain}";

        return Cache::remember($key, 3600, function () use ($shopDomain): array {
            try {
                $response = $this->storefrontApi()->query('storefront/policies/get_all_policies');
                $shop = $response['data']['shop'] ?? [];
                $out = [];
                foreach (['privacyPolicy', 'termsOfService', 'refundPolicy', 'shippingPolicy'] as $k) {
                    if (! empty($shop[$k]['body'])) {
                        $policy = $shop[$k];
                        // Shopify returns an internal `checkout.shopify.com/.../{id}.html`
                        // URL — rewrite to the public storefront policy page so
                        // citation links actually open for the customer.
                        $policy['url'] = $this->publicPolicyUrl($policy['handle'] ?? null, $shopDomain, $policy['url'] ?? '');
                        $out[$k] = $policy;
                    }
                }

                return $out;
            } catch (Throwable $e) {
                Log::channel('ai')->warning('storefront.policies_fetch_failed', [
                    'shop_domain' => $shopDomain,
                    'error' => $e->getMessage(),
                ]);

                return [];
            }
        });
    }

    /**
     * Build the public-facing policy URL `https://{shop}/policies/{handle}`.
     * Prefers the configured public store URL, then the live shop domain, and
     * only keeps Shopify's internal checkout URL as a last resort.
     */
    private function publicPolicyUrl(?string $handle, string $shopDomain, string $fallback): string
    {
        $handle = is_string($handle) ? trim($handle) : '';
        if ($handle === '') {
            return $fallback;
        }

        $base = (string) config('shopify.store_url');
        if ($base === '') {
            $host = $shopDomain !== '' ? $shopDomain : (string) config('shopify.store_domain');
            $base = $host !== '' ? 'https://'.$host : '';
        }
        if ($base === '') {
            return $fallback;
        }

        return rtrim($base, '/').'/policies/'.$handle;
    }

    private function htmlToText(string $html): string
    {
        $clean = strip_tags(str_replace(['</p>', '<br>', '<br/>', '<br />', '</li>'], "\n", $html));
        $clean = preg_replace("/[ \t]+/", ' ', $clean) ?? $clean;
        $clean = preg_replace("/\n{3,}/", "\n\n", $clean) ?? $clean;

        return trim(html_entity_decode($clean, ENT_QUOTES | ENT_HTML5));
    }

    private function emitAuthRequired(ChatSessionContext $ctx): ToolResult
    {
        Log::channel('ai')->info('tool.auth_required', [
            'session_id' => $ctx->sessionId,
            'shop_domain' => $ctx->shopDomain,
            'has_row' => AiCustomerSession::query()->where('session_id', $ctx->sessionId)->exists(),
        ]);

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

    private function resolveCustomerToken(string $sessionId, string $shopDomain = ''): ?string
    {
        $row = AiCustomerSession::query()
            ->where('session_id', $sessionId)
            ->first();

        if ($row === null) {
            return null;
        }

        if (! $row->isExpired()) {
            return $row->customer_access_token;
        }

        // Access token expired — silently exchange refresh_token before
        // falling back to the auth_required popup. Saves the user from a
        // re-OAuth every hour.
        $refreshed = $this->refreshCustomerToken($row, $shopDomain);

        return $refreshed?->customer_access_token;
    }

    /**
     * Shopify Customer Account refresh tokens are SINGLE-USE and rotate on every
     * exchange. Two turns hitting the expired row at once would both present the
     * same refresh_token — Shopify honours the first, rotates it, and rejects the
     * second with `invalid_grant`, which previously left the row holding a dead
     * token forever (silent "please sign in" loop, since /status still reported
     * the 13-day refresh window as authenticated). We therefore:
     *   1. serialise the exchange behind a per-session lock + re-read inside it,
     *      so concurrent turns reuse the freshly-rotated token instead of
     *      double-spending the refresh_token;
     *   2. on a definitive `invalid_grant` (token truly dead), invalidate the
     *      session so /status flips to authenticated:false and the widget
     *      re-opens the OAuth popup — a clean re-sign-in instead of a loop.
     * Transient (network / 5xx) failures leave the row intact so a later turn
     * can retry the refresh.
     */
    private function refreshCustomerToken(AiCustomerSession $row, string $shopDomain): ?AiCustomerSession
    {
        $refreshToken = $row->refresh_token;
        if (! is_string($refreshToken) || $refreshToken === '') {
            return null;
        }

        if ($row->refresh_token_expires_at !== null && $row->refresh_token_expires_at->isPast()) {
            return null;
        }

        if ($shopDomain === '') {
            // Cannot discover the token endpoint without a shop domain; the
            // caller is responsible for passing it. Fail soft so we don't
            // explode an in-flight MCP tool call.
            Log::channel('ai')->warning('oauth.refresh_skipped_no_shop', [
                'session_id' => $row->session_id,
            ]);

            return null;
        }

        $lock = Cache::lock('ai:oauth:refresh:'.$row->getKey(), 15);

        if (! $lock->get()) {
            // Another turn is mid-refresh. Don't double-spend the single-use
            // refresh_token — re-read the row and reuse the rotated token if it
            // landed; otherwise let this turn fall through to auth_required.
            $fresh = AiCustomerSession::query()->find($row->getKey());

            return ($fresh !== null && ! $fresh->isExpired()) ? $fresh : null;
        }

        try {
            // Re-read inside the lock: a sibling turn may have already rotated
            // the token while we were waiting to acquire it.
            $row->refresh();
            if (! $row->isExpired()) {
                return $row;
            }

            $refreshToken = (string) $row->refresh_token;
            if ($refreshToken === '') {
                return null;
            }

            try {
                $config = $this->discoverOidcConfig($shopDomain);
            } catch (Throwable $e) {
                Log::channel('ai')->warning('oauth.refresh_discovery_failed', [
                    'session_id' => $row->session_id,
                    'shop' => $shopDomain,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            $clientId = (string) config('chatbot.oauth.client_id');
            $clientSecret = (string) config('chatbot.oauth.client_secret');

            $request = Http::asForm();
            if ($clientSecret !== '') {
                $request = $request->withBasicAuth($clientId, $clientSecret);
            }

            try {
                $response = $request->post($config['token_endpoint'], [
                    'grant_type' => 'refresh_token',
                    'client_id' => $clientId,
                    'refresh_token' => $refreshToken,
                ]);
            } catch (Throwable $e) {
                // Network-level failure (timeout, DNS). Transient — keep the row.
                Log::channel('ai')->warning('oauth.refresh_transport_error', [
                    'session_id' => $row->session_id,
                    'error' => $e->getMessage(),
                ]);

                return null;
            }

            if (! $response->successful()) {
                $error = (string) $response->json('error');
                Log::channel('ai')->warning('oauth.refresh_failed', [
                    'session_id' => $row->session_id,
                    'http_status' => $response->status(),
                    'error' => $error,
                    'error_description' => $response->json('error_description'),
                ]);

                // Token is definitively dead (rejected, not a transient blip).
                // Drop the session so the widget re-prompts a real sign-in
                // instead of looping on a refresh that can never succeed.
                if ($this->isDeadGrant($response->status(), $error)) {
                    $this->invalidateCustomerSession($row, $error);
                }

                return null;
            }

            $payload = (array) $response->json();
            $accessToken = (string) ($payload['access_token'] ?? '');
            if ($accessToken === '') {
                return null;
            }

            $expiresIn = (int) ($payload['expires_in'] ?? config('chatbot.oauth.token_ttl_seconds', 3600));
            $newRefresh = (string) ($payload['refresh_token'] ?? '');

            // Shopify rotates refresh_tokens on each use — persist whatever was
            // returned so the next refresh has a valid token to present.
            $row->customer_access_token = $accessToken;
            $row->expires_at = now()->addSeconds($expiresIn);
            if ($newRefresh !== '') {
                $row->refresh_token = $newRefresh;
                $refreshExpiresIn = (int) ($payload['refresh_token_expires_in']
                    ?? config('chatbot.oauth.refresh_token_ttl_seconds', 13 * 24 * 3600));
                $row->refresh_token_expires_at = now()->addSeconds($refreshExpiresIn);
            }
            $row->save();

            Log::channel('ai')->info('oauth.token_refreshed', [
                'session_id' => $row->session_id,
                'expires_in' => $expiresIn,
                'rotated_refresh' => $newRefresh !== '',
            ]);

            return $row;
        } finally {
            $lock->release();
        }
    }

    /**
     * A refresh response that means the stored refresh_token can never succeed
     * again (vs a transient 5xx / network blip worth retrying later).
     */
    private function isDeadGrant(int $httpStatus, string $error): bool
    {
        if (in_array($error, ['invalid_grant', 'invalid_token', 'unauthorized_client', 'invalid_request'], true)) {
            return true;
        }

        // Any other 400/401 from the token endpoint is a rejected grant too.
        return in_array($httpStatus, [400, 401], true);
    }

    /**
     * Clear the persisted token so the OAuth /status endpoint reports
     * authenticated:false and the widget re-opens the sign-in popup. The
     * AiConversation row is untouched (the chat thread survives a re-auth).
     */
    private function invalidateCustomerSession(AiCustomerSession $row, string $reason): void
    {
        try {
            $row->forceFill([
                'customer_access_token' => '',
                'refresh_token' => null,
                'refresh_token_expires_at' => null,
                'expires_at' => now()->subSecond(),
            ])->save();
        } catch (Throwable $e) {
            Log::channel('ai')->warning('oauth.invalidate_failed', [
                'session_id' => $row->session_id,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        Log::channel('ai')->info('oauth.session_invalidated', [
            'session_id' => $row->session_id,
            'reason' => $reason,
        ]);
    }

    /**
     * @return array{authorization_endpoint: string, token_endpoint: string}
     */
    private function discoverOidcConfig(string $shopDomain): array
    {
        $cacheKey = "ai:oauth:oidc:{$shopDomain}";

        return Cache::remember($cacheKey, 3600, function () use ($shopDomain): array {
            $url = "https://{$shopDomain}/.well-known/openid-configuration";
            $response = Http::timeout(10)->acceptJson()->get($url);
            abort_unless($response->successful(), 502, 'OIDC discovery failed.');

            $config = (array) $response->json();
            $auth = $config['authorization_endpoint'] ?? null;
            $token = $config['token_endpoint'] ?? null;
            abort_unless(is_string($auth) && is_string($token), 502, 'OIDC discovery payload incomplete.');

            return ['authorization_endpoint' => $auth, 'token_endpoint' => $token];
        });
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
            $pid = $args['product_id'] ?? '';
            // Only convert when caller sent a bare numeric — bare handles
            // (alphanumeric + dashes) are left intact so the downstream
            // `resolveProductIdFromQuery` path can fall back to a search.
            if (is_string($pid) && ctype_digit($pid)) {
                $args['product_id'] = $this->toGid('Product', $pid);
            }
        }

        // Drop a bogus cart_id (empty string, placeholder, or anything that
        // is not a real Shopify Cart GID). The model frequently invents one
        // on the first add — leaving it in makes Shopify reject the call
        // with "Invalid cart_id format" instead of minting a fresh cart.
        // Applies to every Storefront cart op, not just update_cart.
        if (in_array($toolName, [ToolDefinitions::TOOL_UPDATE_CART, ToolDefinitions::TOOL_GET_CART], true)
            && isset($args['cart_id'])
        ) {
            $cid = is_string($args['cart_id']) ? trim($args['cart_id']) : '';
            if (! str_starts_with($cid, 'gid://shopify/Cart/')) {
                unset($args['cart_id']);
            }
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

    /**
     * Resolve a free-text query (handle, title, partial) to a Shopify product
     * GID via a single `search_catalog` round-trip. Returns null when nothing
     * matches.
     */
    private function resolveProductIdFromQuery(string $query, ChatSessionContext $ctx): ?string
    {
        $query = trim($query);
        if ($query === '') {
            return null;
        }

        // Use the Storefront product text-search (real relevance) — the MCP
        // search_catalog returns the same default catalogue for every query so
        // its first result would be the wrong product.
        try {
            $resp = $this->storefrontApi()->query('storefront/products/get_all_products', [
                'limit' => 1,
                'query' => $query,
            ]);
            $node = $resp['data']['products']['edges'][0]['node'] ?? null;
        } catch (Throwable $e) {
            $this->logToolError('resolve_product.storefront', $ctx, $e);

            return null;
        }

        if (! is_array($node) || empty($node['id'])) {
            return null;
        }

        return (string) $node['id'];
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
