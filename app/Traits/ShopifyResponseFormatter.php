<?php

namespace App\Traits;

trait ShopifyResponseFormatter
{
    /**
     * Parse/Paginate edges from a Shopify GraphQL response
     */
    protected static function parseEdges(array $data, string $key)
    {
        $edges = data_get($data, "$key.edges", []);

        return [
            'items' => array_column($edges, 'node'),
            'next_cursor' => data_get($data, "$key.pageInfo.endCursor"),
            'has_more' => data_get($data, "$key.pageInfo.hasNextPage"),
        ];
    }
    protected static function parseConnection($connection, $key = 'items')
    {
        if (
            !$connection ||
            !isset($connection['edges']) ||
            !is_array($connection['edges'])
        ) {
            return [
                $key => [],
                'next_cursor' => null,
                'has_more' => false,
            ];
        }

        $items = array_map(fn($edge) => $edge['node'] ?? null, $connection['edges']);
        $items = array_filter($items);
        $items = array_values($items);

        return [
            $key => $items,
            'next_cursor' => data_get($connection, 'pageInfo.endCursor'),
            'has_more' => data_get($connection, 'pageInfo.hasNextPage', false),
        ];
    }


    /**
     * Recursively remove Shopify edges/node wrappers and flatten data.
     *
     * Works for ANY nested structure:
     * - images.edges[].node
     * - variants.edges[].node
     * - collections.edges[].node
     * - relatedProducts.edges[].node
     * - etc.
     */
    private function refineNestedEdges($data)
    {
        // If array is list of items → process each item
        if (is_array($data) && array_keys($data) === range(0, count($data) - 1)) {
            return array_map([$this, 'refineNestedEdges'], $data);
        }

        // If not an array → return as-is
        if (!is_array($data)) {
            return $data;
        }

        // If array has edges (Shopify connection)
        if (isset($data['edges']) && is_array($data['edges'])) {
            $nodes = [];

            foreach ($data['edges'] as $edge) {
                if (isset($edge['node'])) {
                    $nodes[] = $this->refineNestedEdges($edge['node']);
                }
            }

            return $nodes; // return clean array of nodes
        }

        // Otherwise recur normally on associative array
        $clean = [];
        foreach ($data as $key => $value) {
            $clean[$key] = $this->refineNestedEdges($value);
        }

        return $clean;
    }



    /**
     * Standard success response
     */
    protected static function success($msg, $data = [])
    {
        return response()->json([
            'status' => 200,
            'message' => $msg,
            'data' => $data
        ], 200);
    }

    /**
     * Standard failure response
     */
    protected static function fail($msg, $error = null, $code = 500)
    {
        return response()->json([
            'status' => $code,
            'message' => $msg,
            'error' => $error
        ], $code);
    }
}
