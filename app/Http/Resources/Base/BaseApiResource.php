<?php

namespace App\Http\Resources\Base;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Base API Resource
 * 
 * Provides common transformation logic for all API resources.
 * Includes methods for flattening Shopify edge/node structures.
 * 
 * Requirements: 5.5, 17.6, 17.7
 */
abstract class BaseApiResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     * 
     * Concrete classes should override this method to define their specific transformation.
     * 
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // Default implementation returns the resource as-is
        // Concrete classes should override this method
        return parent::toArray($request);
    }

    /**
     * Flatten Shopify edge/node structures to simple arrays.
     * 
     * Recursively processes nested structures and removes GraphQL connection wrappers.
     * Handles any nested structure like:
     * - images.edges[].node
     * - variants.edges[].node
     * - collections.edges[].node
     * 
     * @param mixed $data
     * @return mixed
     */
    protected function flattenEdges(mixed $data): mixed
    {
        // If array is a list of items → process each item
        if (is_array($data) && array_keys($data) === range(0, count($data) - 1)) {
            return array_map(fn($item) => $this->flattenEdges($item), $data);
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
                    $nodes[] = $this->flattenEdges($edge['node']);
                }
            }

            return $nodes; // return clean array of nodes
        }

        // Otherwise recurse on associative array
        $clean = [];
        foreach ($data as $key => $value) {
            $clean[$key] = $this->flattenEdges($value);
        }

        return $clean;
    }

    /**
     * Parse a Shopify connection structure with pagination info.
     * 
     * Extracts items from edges/nodes and includes pagination metadata.
     * 
     * @param array|null $connection
     * @param string $key The key name for items in the result (default: 'items')
     * @return array{items: array, next_cursor: string|null, has_more: bool}
     */
    protected function parseConnection(?array $connection, string $key = 'items'): array
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

        // Recursively flatten nested edges in each item
        $items = array_map(fn($item) => $this->flattenEdges($item), $items);

        return [
            $key => $items,
            'next_cursor' => data_get($connection, 'pageInfo.endCursor'),
            'has_more' => data_get($connection, 'pageInfo.hasNextPage', false),
        ];
    }

    /**
     * Remove Shopify internal fields from data.
     * 
     * Removes fields like __typename, edges, nodes that are GraphQL-specific.
     * 
     * @param array $data
     * @param array $fieldsToRemove Additional fields to remove
     * @return array
     */
    protected function removeInternalFields(array $data, array $fieldsToRemove = []): array
    {
        $defaultFieldsToRemove = ['__typename', 'edges', 'nodes', 'pageInfo'];
        $allFieldsToRemove = array_merge($defaultFieldsToRemove, $fieldsToRemove);

        foreach ($allFieldsToRemove as $field) {
            unset($data[$field]);
        }

        return $data;
    }

    /**
     * Extract pagination metadata from a Shopify connection.
     * 
     * @param array|null $connection
     * @return array{next_cursor: string|null, has_more: bool, total_count: int|null}
     */
    protected function extractPaginationMeta(?array $connection): array
    {
        if (!$connection) {
            return [
                'next_cursor' => null,
                'has_more' => false,
                'total_count' => null,
            ];
        }

        return [
            'next_cursor' => data_get($connection, 'pageInfo.endCursor'),
            'has_more' => data_get($connection, 'pageInfo.hasNextPage', false),
            'total_count' => data_get($connection, 'totalCount'),
        ];
    }

    /**
     * Transform a monetary amount from Shopify format.
     * 
     * Shopify returns amounts as objects with 'amount' and 'currencyCode'.
     * This method extracts and formats them consistently.
     * 
     * @param array|null $moneyData
     * @return array{amount: string, currency: string}|null
     */
    protected function transformMoney(?array $moneyData): ?array
    {
        if (!$moneyData || !isset($moneyData['amount'])) {
            return null;
        }

        return [
            'amount' => $moneyData['amount'],
            'currency' => $moneyData['currencyCode'] ?? 'GBP',
        ];
    }

    /**
     * Transform an image from Shopify format.
     * 
     * @param array|null $imageData
     * @return array{url: string, alt: string|null}|null
     */
    protected function transformImage(?array $imageData): ?array
    {
        if (!$imageData || !isset($imageData['url'])) {
            return null;
        }

        return [
            'url' => $imageData['url'],
            'alt' => $imageData['altText'] ?? null,
        ];
    }

    /**
     * Transform an array of images from Shopify format.
     * 
     * @param array|null $imagesData
     * @return array
     */
    protected function transformImages(?array $imagesData): array
    {
        if (!$imagesData) {
            return [];
        }

        // If it's a connection structure, flatten it first
        if (isset($imagesData['edges'])) {
            $imagesData = $this->flattenEdges($imagesData);
        }

        return array_map(
            fn($image) => $this->transformImage($image),
            array_filter($imagesData)
        );
    }
}
