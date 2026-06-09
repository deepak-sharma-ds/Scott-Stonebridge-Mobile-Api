<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI\Tools;

use App\Services\AI\Tools\ToolDefinitions;
use Tests\TestCase;

class ToolDefinitionsTest extends TestCase
{
    public function test_returns_ten_function_tools(): void
    {
        $tools = (new ToolDefinitions)->all();

        $this->assertCount(10, $tools);
        foreach ($tools as $tool) {
            $this->assertSame('function', $tool['type']);
            $this->assertArrayHasKey('function', $tool);
            $this->assertNotEmpty($tool['function']['name']);
            $this->assertNotEmpty($tool['function']['description']);
            $this->assertSame('object', $tool['function']['parameters']['type']);
        }
    }

    public function test_tool_names_are_unique_and_cover_expected_set(): void
    {
        $names = array_map(static fn ($t) => $t['function']['name'], (new ToolDefinitions)->all());

        $this->assertSame(count($names), count(array_unique($names)));
        $this->assertEqualsCanonicalizing([
            ToolDefinitions::TOOL_SEARCH_CATALOG,
            ToolDefinitions::TOOL_GET_PRODUCT_DETAILS,
            ToolDefinitions::TOOL_GET_CART,
            ToolDefinitions::TOOL_UPDATE_CART,
            ToolDefinitions::TOOL_SEARCH_POLICIES,
            ToolDefinitions::TOOL_GET_ORDER_STATUS,
            ToolDefinitions::TOOL_GET_MOST_RECENT_ORDER_STATUS,
            ToolDefinitions::TOOL_START_CHECKOUT,
            ToolDefinitions::TOOL_SUGGEST_QUICK_REPLIES,
            ToolDefinitions::TOOL_SUGGEST_UPSELL,
        ], $names);
    }

    public function test_dispatch_buckets_have_no_overlap(): void
    {
        $overlap = array_intersect(
            ToolDefinitions::STOREFRONT_MCP_TOOLS,
            ToolDefinitions::CUSTOMER_MCP_TOOLS,
            ToolDefinitions::INTERNAL_TOOLS,
        );
        $this->assertSame([], $overlap);
    }
}
