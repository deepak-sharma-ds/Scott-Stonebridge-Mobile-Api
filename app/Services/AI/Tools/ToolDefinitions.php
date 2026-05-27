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

    public const TOOL_START_CHECKOUT = 'start_checkout';

    public const TOOL_SUGGEST_QUICK_REPLIES = 'suggest_quick_replies';

    public const TOOL_SUGGEST_UPSELL = 'suggest_upsell';

    /** @var list<string> */
    public const STOREFRONT_MCP_TOOLS = [
        self::TOOL_SEARCH_CATALOG,
        self::TOOL_GET_PRODUCT_DETAILS,
        self::TOOL_GET_CART,
        self::TOOL_UPDATE_CART,
        self::TOOL_SEARCH_POLICIES,
    ];

    /** @var list<string> */
    public const CUSTOMER_MCP_TOOLS = [
        self::TOOL_GET_ORDER_STATUS,
        self::TOOL_GET_MOST_RECENT_ORDER_STATUS,
    ];

    /**
     * Internal tools are not proxied to any MCP server — handled directly by
     * ToolExecutor. `start_checkout` is synthesised from a `get_cart` call so
     * the AI can hand back the Shopify-hosted `cart.checkout_url`.
     *
     * @var list<string>
     */
    public const INTERNAL_TOOLS = [
        self::TOOL_SUGGEST_QUICK_REPLIES,
        self::TOOL_SUGGEST_UPSELL,
        self::TOOL_START_CHECKOUT,
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
                'Use ONLY when the user asks what is currently in their cart and you have no recent cart_state from this turn. Never call right before start_checkout — start_checkout already reads the live cart. Never pass placeholder IDs.',
                [
                    'type' => 'object',
                    'properties' => [
                        'cart_id' => ['type' => 'string', 'minLength' => 1],
                    ],
                    'required' => ['cart_id'],
                    'additionalProperties' => false,
                ],
            ),
            $this->fn(self::TOOL_UPDATE_CART,
                'Use to add, change, or remove cart items. `cart_id` is OPTIONAL — when absent Shopify creates a new cart. Use `add_items` for new variants. To change quantity or remove a line, use the cart line `id` from the latest cart_state `items[].id` (NOT the variant id): `update_items` with that line id to change quantity, `remove_line_ids` with that line id to drop it.',
                [
                    'type' => 'object',
                    'properties' => [
                        'cart_id' => ['type' => 'string'],
                        'add_items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'product_variant_id' => ['type' => 'string', 'minLength' => 1],
                                    'quantity' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 99],
                                ],
                                'required' => ['product_variant_id', 'quantity'],
                                'additionalProperties' => false,
                            ],
                        ],
                        'update_items' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'id' => ['type' => 'string', 'minLength' => 1],
                                    'quantity' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 99],
                                ],
                                'required' => ['id', 'quantity'],
                                'additionalProperties' => false,
                            ],
                        ],
                        'remove_line_ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'minLength' => 1],
                        ],
                        'discount_codes' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'minLength' => 1],
                        ],
                    ],
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
            $this->fn(self::TOOL_GET_ORDER_STATUS,
                'Use when the user asks about a SPECIFIC order by number ("where is order #1234?"). Requires the customer to be signed in; the system returns auth_required if not.',
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
                'Use when the user asks about their latest order without naming a number ("where is my order?", "did my order ship?"). Requires the customer to be signed in.',
                [
                    'type' => 'object',
                    'properties' => new \stdClass,
                    'additionalProperties' => false,
                ],
            ),
            $this->fn(self::TOOL_START_CHECKOUT,
                'Call IMMEDIATELY (do NOT pre-call get_cart) when the user says they want to check out, buy, place order, pay, or are ready to purchase. Returns the Shopify-hosted checkout URL surfaced from the live cart. Pass the latest cart_id you have seen in any previous cart_state result — do not invent or use placeholder IDs from the schema description.',
                [
                    'type' => 'object',
                    'properties' => [
                        'cart_id' => ['type' => 'string', 'minLength' => 1],
                    ],
                    'required' => ['cart_id'],
                    'additionalProperties' => false,
                ],
            ),
            $this->fn(self::TOOL_SUGGEST_QUICK_REPLIES,
                'Use when the conversation reaches a decision point and the user would benefit from 2-5 short tap-to-send suggestions ("Tell me more", "Add to cart", "Show similar").',
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
                'Use immediately after a successful add-to-cart, or when the cart total is under the free-shipping threshold, to surface complementary products and the gap to free shipping.',
                [
                    'type' => 'object',
                    'properties' => [
                        'cart_id' => ['type' => 'string', 'minLength' => 1],
                    ],
                    'required' => ['cart_id'],
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
