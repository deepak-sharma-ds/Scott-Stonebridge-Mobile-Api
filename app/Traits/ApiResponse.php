<?php

declare(strict_types=1);

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponse
{
    /**
     * Return success response
     */
    protected function success(
        string $message, 
        mixed $data = null, 
        int $code = 200
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
        ], $code);
    }
    
    /**
     * Return error response
     */
    protected function error(
        string $message, 
        mixed $errors = null, 
        int $code = 400
    ): JsonResponse {
        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
        ], $code);
    }
    
    /**
     * Return paginated response
     */
    protected function paginated(
        string $message,
        $collection,
        string $resourceClass
    ): JsonResponse {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $resourceClass::collection($collection),
            'meta' => [
                'current_page' => $collection->currentPage(),
                'last_page' => $collection->lastPage(),
                'per_page' => $collection->perPage(),
                'total' => $collection->total(),
            ],
        ]);
    }

    /**
     * Alias for error() to support legacy controllers
     */
    protected function fail(string $message, mixed $errors = null, int $code = 400): JsonResponse
    {
        return $this->error($message, $errors, $code);
    }

    /**
     * Validation Error Response
     */
    protected function validationError($errors): JsonResponse
    {
        return $this->error('Validation failed', $errors, 422);
    }

    /**
     * Recursively remove Shopify edges/node wrappers and flatten data.
     */
    protected function refineNestedEdges($data)
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
     * Parse Connection (Legacy helper)
     */
    protected function parseConnection($connection, $key = 'items')
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
        $items = array_values(array_filter($items));

        return [
            $key => $items,
            'next_cursor' => data_get($connection, 'pageInfo.endCursor'),
            'has_more' => data_get($connection, 'pageInfo.hasNextPage', false),
        ];
    }
}
