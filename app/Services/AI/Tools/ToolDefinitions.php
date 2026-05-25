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

    public const TOOL_GET_PRODUCT = 'get_product';

    public const TOOL_LOOKUP_CATALOG = 'lookup_catalog';

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
        self::TOOL_GET_PRODUCT,
        self::TOOL_LOOKUP_CATALOG,
        self::TOOL_GET_CART,
        self::TOOL_UPDATE_CART,
        self::TOOL_SEARCH_POLICIES,
        self::TOOL_START_CHECKOUT,
    ];

    /** @var list<string> */
    public const CUSTOMER_MCP_TOOLS = [
        self::TOOL_GET_ORDER_STATUS,
        self::TOOL_GET_MOST_RECENT_ORDER_STATUS,
    ];

    /** @var list<string> */
    public const INTERNAL_TOOLS = [
        self::TOOL_SUGGEST_QUICK_REPLIES,
        self::TOOL_SUGGEST_UPSELL,
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
            $this->fn(self::TOOL_GET_PRODUCT,
                'Use when the user taps a product card or asks for full details on one specific item (description, variants, stock). Always call this before quoting variant price or availability.',
                [
                    'type' => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'string', 'minLength' => 1],
                    ],
                    'required' => ['product_id'],
                    'additionalProperties' => false,
                ],
            ),
            $this->fn(self::TOOL_LOOKUP_CATALOG,
                'Use when you already know up to ten Shopify product IDs and need fresh details for all of them in one round-trip (typical use: rebuilding a recently-viewed strip).',
                [
                    'type' => 'object',
                    'properties' => [
                        'ids' => [
                            'type' => 'array',
                            'items' => ['type' => 'string', 'minLength' => 1],
                            'minItems' => 1,
                            'maxItems' => 10,
                        ],
                    ],
                    'required' => ['ids'],
                    'additionalProperties' => false,
                ],
            ),
            $this->fn(self::TOOL_GET_CART,
                'Use when the user asks about their cart contents or you need the live cart state before suggesting a checkout.',
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
                'Use to add, increment, decrement, or remove items in the cart. Each `lines` row sets the absolute quantity for the merchandise — use quantity:0 to remove.',
                [
                    'type' => 'object',
                    'properties' => [
                        'cart_id' => ['type' => 'string', 'minLength' => 1],
                        'lines' => [
                            'type' => 'array',
                            'minItems' => 1,
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'merchandise_id' => ['type' => 'string'],
                                    'quantity' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 99],
                                ],
                                'required' => ['merchandise_id', 'quantity'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['cart_id', 'lines'],
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
                'Use when the user is ready to buy ("checkout", "place order", "I want to pay now"). Returns a Shopify-hosted checkout URL; the UI opens it in a new tab — do NOT attempt payment yourself.',
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
