<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

/**
 * ApiResponse Trait
 * 
 * Provides standardized API response formatting with:
 * - Consistent response structure (success, message, data, meta)
 * - Edge/node flattening for Shopify GraphQL responses
 * - Pagination metadata formatting
 * 
 * Refactored from ShopifyResponseFormatter to align with enterprise patterns.
 * 
 * @see Requirements 5.9, 9.3, 9.4, 9.5
 */
trait ApiResponse
{
    /**
     * Standard success response
     * 
     * @param string $message Success message
     * @param array $data Response data
     * @param array $meta Additional metadata (correlation_id, timestamp, etc.)
     * @param int $statusCode HTTP status code
     * @return JsonResponse
     */
    protected function successResponse(
        string $message,
        array $data = [],
        array $meta = [],
        int $statusCode = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'meta' => array_merge([
                'timestamp' => now()->toIso8601String(),
            ], $meta),
        ], $statusCode);
    }

    /**
     * Standard error response
     * 
     * @param string $message Error message
     * @param array $data Additional error data
     * @param array $meta Additional metadata (error_code, correlation_id, etc.)
     * @param int $statusCode HTTP status code
     * @return JsonResponse
     */
    protected function errorResponse(
        string $message,
        array $data = [],
        array $meta = [],
        int $statusCode = 500
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data,
            'meta' => array_merge([
                'timestamp' => now()->toIso8601String(),
            ], $meta),
        ], $statusCode);
    }

    /**
     * Parse Shopify GraphQL connection (edges/nodes) with pagination
     * 
     * Transforms Shopify's edge/node structure into a flat array with pagination metadata.
     * 
     * Example input:
     * [
     *   'edges' => [
     *     ['node' => ['id' => '1', 'title' => 'Product 1']],
     *     ['node' => ['id' => '2', 'title' => 'Product 2']]
     *   ],
     *   'pageInfo' => ['endCursor' => 'abc123', 'hasNextPage' => true]
     * ]
     * 
     * Example output:
     * [
     *   'items' => [
     *     ['id' => '1', 'title' => 'Product 1'],
     *     ['id' => '2', 'title' => 'Product 2']
     *   ],
     *   'pagination' => [
     *     'next_cursor' => 'abc123',
     *     'has_more' => true
     *   ]
     * ]
     * 
     * @param array|null $connection Shopify connection object
     * @param string $itemsKey Key name for items array in output
     * @return array Flattened array with items and pagination
     */
    protected function parseConnection(?array $connection, string $itemsKey = 'items'): array
    {
        if (
            !$connection ||
            !isset($connection['edges']) ||
            !is_array($connection['edges'])
        ) {
            return [
                $itemsKey => [],
                'pagination' => [
                    'next_cursor' => null,
                    'has_more' => false,
                ],
            ];
        }

        $items = array_map(fn($edge) => $edge['node'] ?? null, $connection['edges']);
        $items = array_filter($items);
        $items = array_values($items);

        return [
            $itemsKey => $items,
            'pagination' => [
                'next_cursor' => data_get($connection, 'pageInfo.endCursor'),
                'has_more' => data_get($connection, 'pageInfo.hasNextPage', false),
            ],
        ];
    }

    /**
     * Parse edges from a nested Shopify GraphQL response
     * 
     * Convenience method for extracting connection data from nested paths.
     * 
     * @param array $data Full GraphQL response
     * @param string $key Dot-notation path to connection (e.g., 'data.products')
     * @param string $itemsKey Key name for items array in output
     * @return array Flattened array with items and pagination
     */
    protected function parseEdges(array $data, string $key, string $itemsKey = 'items'): array
    {
        $connection = data_get($data, $key);
        return $this->parseConnection($connection, $itemsKey);
    }

    /**
     * Recursively flatten Shopify edges/nodes throughout nested data structures
     * 
     * This method walks through any nested structure and converts all Shopify
     * edge/node patterns into flat arrays. Useful for deeply nested responses
     * like products with variants, images, collections, etc.
     * 
     * Example:
     * Input:  ['images' => ['edges' => [['node' => ['url' => 'img1.jpg']]]]]
     * Output: ['images' => [['url' => 'img1.jpg']]]
     * 
     * @param mixed $data Data to flatten
     * @return mixed Flattened data
     */
    protected function flattenEdges($data)
    {
        // If array is a list → process each item
        if (is_array($data) && array_keys($data) === range(0, count($data) - 1)) {
            return array_map([$this, 'flattenEdges'], $data);
        }

        // If not an array → return as-is
        if (!is_array($data)) {
            return $data;
        }

        // If array has edges (Shopify connection) → flatten to nodes
        if (isset($data['edges']) && is_array($data['edges'])) {
            $nodes = [];

            foreach ($data['edges'] as $edge) {
                if (isset($edge['node'])) {
                    $nodes[] = $this->flattenEdges($edge['node']);
                }
            }

            return $nodes;
        }

        // Otherwise recurse on associative array
        $flattened = [];
        foreach ($data as $key => $value) {
            $flattened[$key] = $this->flattenEdges($value);
        }

        return $flattened;
    }

    /**
     * Format pagination metadata for API responses
     * 
     * @param string|null $nextCursor Cursor for next page
     * @param bool $hasMore Whether more pages exist
     * @param int|null $totalCount Total count if available
     * @return array Formatted pagination metadata
     */
    protected function formatPagination(
        ?string $nextCursor,
        bool $hasMore,
        ?int $totalCount = null
    ): array {
        $pagination = [
            'next_cursor' => $nextCursor,
            'has_more' => $hasMore,
        ];

        if ($totalCount !== null) {
            $pagination['total_count'] = $totalCount;
        }

        return $pagination;
    }

    /**
     * Remove Shopify internal fields from data
     * 
     * Removes fields like __typename, admin_graphql_api_id, etc.
     * 
     * @param array $data Data to clean
     * @param array $fieldsToRemove Additional fields to remove
     * @return array Cleaned data
     */
    protected function removeInternalFields(array $data, array $fieldsToRemove = []): array
    {
        $defaultFieldsToRemove = ['__typename', 'admin_graphql_api_id'];
        $allFieldsToRemove = array_merge($defaultFieldsToRemove, $fieldsToRemove);

        foreach ($allFieldsToRemove as $field) {
            unset($data[$field]);
        }

        // Recursively clean nested arrays
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->removeInternalFields($value, $fieldsToRemove);
            }
        }

        return $data;
    }
}
