<?php

declare(strict_types=1);

namespace App\Services\AI\Tools;

/**
 * OpenAI `tools[]` array for the AI sales agent. Each definition is shaped
 * `{type: 'function', function: {name, description, parameters: <JSON Schema>}}`.
 * Descriptions are phrased as "Use when ..." so the model picks the right
 * tool without over-relying on the system prompt.
 */
final class ToolDefinitions
{
    public const TOOL_SEARCH_CATALOG = 'search_catalog';

    public const TOOL_GET_PRODUCT_DETAILS = 'get_product_details';

    public const TOOL_GET_CART = 'get_cart';

    public const TOOL_UPDATE_CART = 'update_cart';

    public const TOOL_SEARCH_POLICIES = 'search_shop_policies_and_faqs';

    public const TOOL_GET_ORDER_STATUS = 'get_order_status';

    public const TOOL_GET_MOST_RECENT_ORDER_STATUS = 'get_most_recent_order_status';

    public const TOOL_LIST_CUSTOMER_ORDERS = 'list_customer_orders';

    public const TOOL_START_CHECKOUT = 'start_checkout';

    public const TOOL_SUGGEST_QUICK_REPLIES = 'suggest_quick_replies';

    public const TOOL_SUGGEST_UPSELL = 'suggest_upsell';

    public const TOOL_SEARCH_KNOWLEDGE = 'search_knowledge_base';

    /** @var list<string> */
    public const STOREFRONT_MCP_TOOLS = [
        self::TOOL_SEARCH_CATALOG,
        self::TOOL_GET_PRODUCT_DETAILS,
        self::TOOL_SEARCH_POLICIES,
    ];

    /** @var list<string> */
    public const CUSTOMER_MCP_TOOLS = [
        self::TOOL_GET_ORDER_STATUS,
        self::TOOL_GET_MOST_RECENT_ORDER_STATUS,
        self::TOOL_LIST_CUSTOMER_ORDERS,
    ];

    /**
     * Internal tools are not proxied to any MCP server — handled directly by
     * ToolExecutor. The storefront's own cart (via `context.cart`, the live
     * `/cart.js` snapshot) is the single source of truth for cart state
     * (ADR 0010): `get_cart` reads it directly, `update_cart` emits a
     * `cart_action` intent for the frontend to execute against the theme's
     * native Ajax Cart API, and `start_checkout` emits a `checkout_action`
     * intent to navigate to `/checkout` — none of the three call Shopify.
     *
     * @var list<string>
     */
    public const INTERNAL_TOOLS = [
        self::TOOL_GET_CART,
        self::TOOL_UPDATE_CART,
        self::TOOL_SUGGEST_QUICK_REPLIES,
        self::TOOL_SUGGEST_UPSELL,
        self::TOOL_START_CHECKOUT,
        self::TOOL_SEARCH_KNOWLEDGE,
    ];

    /**
     * @return list<array{type:string, function: array{name:string, description:string, parameters: array<string, mixed>}}>
     */
    public function all(): array
    {
        return [
            $this->fn(self::TOOL_SEARCH_CATALOG,
                'Use when the user asks to discover products by keyword, theme, or vibe ("show me tarot decks", "anything for protection"). Returns the catalogue cards rendered as a carousel — do NOT quote prices from memory.',
                [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'minLength' => 1],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 12],
                        'sort' => ['type' => 'string', 'enum' => ['relevance', 'best_selling', 'price_asc', 'price_desc']],
                        'filters' => ['type' => 'object', 'additionalProperties' => true],
                    ],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
            ),
            $this->fn(self::TOOL_GET_PRODUCT_DETAILS,
                'Use when the user taps a product card or asks for full details on one specific item (description, variants, stock). Always call this before quoting variant price or availability. Prefer passing the `handle` from the product card when you have it — it returns the full variant + image set.',
                [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'string', 'minLength' => 1],
                        'handle' => ['type' => 'string', 'description' => 'The product handle/slug from the card (e.g. "crystal-ball"). Preferred over product_id.'],
                        'options' => [
                            'type' => 'object',
                            'description' => 'Optional variant selectors keyed by option name, e.g. {"Size":"M","Color":"Black"}.',
                            'additionalProperties' => ['type' => 'string'],
                        ],
                    ],
                    'required' => ['product_id'],
                    'additionalProperties' => false,
                ],
            ),
            $this->fn(self::TOOL_GET_CART,
                'Use when the user asks what is currently in their cart. Reads the cart the storefront already sent this turn — no arguments needed.',
                [
                    'type' => 'object',
                    'properties' => new \stdClass,
                    'additionalProperties' => false,
                ],
            ),
            $this->fn(self::TOOL_UPDATE_CART,
                'Use to add, change the quantity of, or remove cart items. To add an item, provide the variant_id (or product handle). If the variant ID is not yet known, call get_product_details first to get it. Action `remove` or quantity 0 removes the item. Action `clear` removes all items. This performs the real cart mutation on the storefront directly — reply as if it already succeeded.',
                [
                    'type' => 'object',
                    'properties' => [
                        'items' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'action' => ['type' => 'string', 'enum' => ['add', 'update', 'remove', 'clear']],
                                    'variant_id' => ['type' => 'string', 'description' => 'Variant ID (or product handle) to add/update/remove. Can be GID or numeric ID.'],
                                    'quantity' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 99, 'description' => 'Quantity for add/update. Set to 0 or use action "remove" to remove.'],
                                ],
                                'required' => ['action'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['items'],
                    'additionalProperties' => false,
                ],
            ),
            $this->fn(self::TOOL_SEARCH_POLICIES,
                'Use when the user asks about shipping, returns, refunds, FAQ, or other store-policy topics. Always cite the source page that backs the answer.',
                [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'minLength' => 1],
                    ],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
            ),
            $this->fn(self::TOOL_SEARCH_KNOWLEDGE,
                'Use when the user asks about Scott Stonebridge, his story, services, blog topics, store pages, or anything that might be covered by the STORE KNOWLEDGE block. Always call this BEFORE saying "I don\'t have that info" — it searches the full local knowledge base (pages, blogs, FAQs, products, scraped URLs) ranked by relevance to the query. Prefer this over `search_shop_policies_and_faqs` for non-policy topics.',
                [
                    'type' => 'object',
                    'properties' => [
                        'query' => ['type' => 'string', 'minLength' => 1, 'description' => 'Natural-language search terms. Use the user\'s own phrasing where possible.'],
                        'content_types' => [
                            'type' => 'array',
                            'description' => 'Optional narrowing filter. Omit unless the user clearly cares about one type.',
                            'items' => ['type' => 'string', 'enum' => ['page', 'policy', 'blog', 'faq', 'custom', 'product', 'url']],
                        ],
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 8],
                    ],
                    'required' => ['query'],
                    'additionalProperties' => false,
                ],
            ),
            $this->fn(self::TOOL_GET_ORDER_STATUS,
                'Use when the user asks about a SPECIFIC order by number ("where is order #1234?"). Requires the customer to be signed in; the system returns auth_required if not. Call this fresh every time the user asks — even if a previous turn returned auth_required, because the customer may have just signed in. Never answer an order question without calling this tool in the current turn.',
                [
                    'type' => 'object',
                    'properties' => [
                        'order_number' => ['type' => 'string', 'minLength' => 1],
                    ],
                    'required' => ['order_number'],
                    'additionalProperties' => false,
                ],
            ),
            $this->fn(self::TOOL_GET_MOST_RECENT_ORDER_STATUS,
                'Use when the user asks about their latest order without naming a number ("where is my order?", "did my order ship?"). Requires the customer to be signed in. Call this fresh every time the user asks — even if a previous turn returned auth_required, because the customer may have just signed in. Never answer an order question without calling this tool in the current turn.',
                [
                    'type' => 'object',
                    'properties' => new \stdClass,
                    'additionalProperties' => false,
                ],
            ),
            $this->fn(self::TOOL_LIST_CUSTOMER_ORDERS,
                'Use when the user wants to see their order history or ALL their orders ("show me my orders", "my past orders", "order history", "list my orders"). Requires the customer to be signed in; the system returns auth_required if not. Returns a list of orders newest-first, each linking to its Shopify order-detail page. To load older orders when the user asks for more, pass the `cursor` value from the previous order_list result. Call this fresh every time the user asks — even if a previous turn returned auth_required, because the customer may have just signed in. Never answer an order question without calling this tool in the current turn.',
                [
                    'type' => 'object',
                    'properties' => [
                        'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 20],
                        'cursor' => ['type' => 'string', 'description' => 'Pagination cursor from the previous order_list result. Omit or pass null if fetching the first page.'],
                    ],
                    'additionalProperties' => false,
                ],
            ),
            $this->fn(self::TOOL_START_CHECKOUT,
                'Call IMMEDIATELY (do NOT pre-call get_cart) when the user says they want to check out, buy, place order, pay, or are ready to purchase. Sends the customer to the storefront\'s checkout for whatever is currently in their cart — no arguments needed.',
                [
                    'type' => 'object',
                    'properties' => new \stdClass,
                    'additionalProperties' => false,
                ],
            ),
            $this->fn(self::TOOL_SUGGEST_QUICK_REPLIES,
                'ALWAYS call this at the end of any turn where the customer must choose a next step (after showing products, product detail, cart state, or a recommendation). Provide 2-5 short tap-to-send suggestions ("Tell me more", "Add to cart", "Show similar"). Skip only for a pure factual one-liner or an auth_required reply.',
                [
                    'type' => 'object',
                    'properties' => [
                        'replies' => [
                            'type' => 'array',
                            'minItems' => 2,
                            'maxItems' => 5,
                            'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 40],
                        ],
                    ],
                    'required' => ['replies'],
                    'additionalProperties' => false,
                ],
            ),
            $this->fn(self::TOOL_SUGGEST_UPSELL,
                'Call this IMMEDIATELY after every successful add-to-cart, and whenever the customer is browsing their cart, to surface complementary products (upsell / cross-sell). Reads the cart the storefront already sent this turn — no arguments needed.',
                [
                    'type' => 'object',
                    'properties' => new \stdClass,
                    'additionalProperties' => false,
                ],
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $parameters
     * @return array{type:string, function: array{name:string, description:string, parameters: array<string, mixed>}}
     */
    private function fn(string $name, string $description, array $parameters): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => $parameters,
            ],
        ];
    }
}
