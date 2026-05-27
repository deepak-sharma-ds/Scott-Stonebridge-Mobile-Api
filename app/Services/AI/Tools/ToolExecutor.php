<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

use App\Contracts\Services\Sales\UpsellServiceInterface;
use App\Contracts\Shopify\StorefrontApiClientInterface;
use App\DTOs\Chat\ProductRecommendationDTO;
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

    private ?StorefrontApiClientInterface $storefrontApi;

    public function __construct(
        private readonly StorefrontMcpClient $storefront,
        private readonly CustomerMcpClient $customer,
        private readonly ChunkEmitter $emitter,
        private readonly UpsellServiceInterface $upsell,
        ?StorefrontApiClientInterface $storefrontApi = null,
    ) {
        $this->storefrontApi = $storefrontApi;
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
        $args = $this->normaliseStorefrontArgs($toolName, $args);

        // When the model hands us a handle / slug instead of a GID for
        // `get_product_details`, resolve it via search_catalog first so the
        // downstream MCP call doesn't bomb with `Variable $id of type ID! was
        // provided invalid value`.
        if ($toolName === ToolDefinitions::TOOL_GET_PRODUCT_DETAILS) {
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

        $result = $this->withCache($toolName, $args, $ctx->shopDomain, fn (): array => $this->storefront->callTool($toolName, $args, $ctx->shopDomain));

        return match ($toolName) {
            ToolDefinitions::TOOL_GET_PRODUCT_DETAILS => $this->handleProductDetail($result),
            ToolDefinitions::TOOL_GET_CART, ToolDefinitions::TOOL_UPDATE_CART => $this->handleCart($result),
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
            $pid = $args['product_id'] ?? '';
            // Only convert when caller sent a bare numeric — bare handles
            // (alphanumeric + dashes) are left intact so the downstream
            // `resolveProductIdFromQuery` path can fall back to a search.
            if (is_string($pid) && ctype_digit($pid)) {
                $args['product_id'] = $this->toGid('Product', $pid);
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
