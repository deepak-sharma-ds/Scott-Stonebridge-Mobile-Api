<?php

namespace App\DTOs\Content;

use App\DTOs\Base\BaseDTO;
use InvalidArgumentException;

/**
 * Page Data Transfer Object
 * 
 * Represents a Shopify page with content and metadata.
 * Used for CMS pages and policy pages in the mobile API.
 * 
 * Requirements: 11.6, 11.11, 11.12
 */
class PageDTO extends BaseDTO
{
    public function __construct(
        public readonly string $id,
        public readonly string $title,
        public readonly string $handle,
        public readonly string $body,
        public readonly ?string $bodySummary,
        public readonly ?array $seo,
        public readonly array $metafields,
        public readonly ?array $metadata,
        public readonly string $createdAt,
        public readonly string $updatedAt,
    ) {
        $this->validate();
    }

    /**
     * Validate the page data.
     * 
     * @throws InvalidArgumentException
     */
    protected function validate(): void
    {
        $this->validateRequired($this->id, 'Page ID');
        $this->validateRequired($this->title, 'Title');
        $this->validateRequired($this->handle, 'Handle');
    }

    /**
     * Create a PageDTO from Shopify API response data.
     * 
     * Transforms raw Shopify GraphQL page response into a typed DTO instance.
     * Handles both regular pages and policy pages.
     * 
     * @param array $data Raw page data from Shopify GraphQL response
     * @return self
     */
    public static function fromShopifyResponse(array $data): self
    {
        $metafields = self::normalizeMetafields($data['metafields'] ?? []);

        return new self(
            id: $data['id'],
            title: $data['title'],
            handle: $data['handle'],
            body: $data['body'] ?? '',
            bodySummary: $data['bodySummary'] ?? null,
            seo: self::normalizeSeo($data['seo'] ?? null),
            metafields: $metafields,
            metadata: $data['metadata'] ?? self::buildMetadataMap($metafields),
            createdAt: $data['createdAt'] ?? now()->toIso8601String(),
            updatedAt: $data['updatedAt'] ?? now()->toIso8601String(),
        );
    }

    /**
     * Normalize SEO payload.
     */
    private static function normalizeSeo(?array $seo): ?array
    {
        if (!$seo) {
            return null;
        }

        return [
            'title' => $seo['title'] ?? null,
            'description' => $seo['description'] ?? null,
        ];
    }

    /**
     * Normalize Shopify metafields to a predictable list.
     *
     * @param mixed $metafields
     * @return array<int, array{namespace: string|null, key: string, value: mixed}>
     */
    private static function normalizeMetafields(mixed $metafields): array
    {
        if (!is_array($metafields)) {
            return [];
        }

        $normalized = [];

        foreach ($metafields as $metafield) {
            if (!is_array($metafield) || empty($metafield['key'])) {
                continue;
            }

            $normalized[] = [
                'namespace' => $metafield['namespace'] ?? null,
                'key' => $metafield['key'],
                'value' => $metafield['value'] ?? null,
                'references' => self::normalizeReferences($metafield['references'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * Normalize Shopify metaobject references into a flat list.
     *
     * @param mixed $references
     * @return array<int, array{id: string, type: string|null, fields: array<string, mixed>}>
     */
    private static function normalizeReferences(mixed $references): array
    {
        if (!is_array($references)) {
            return [];
        }

        $nodes = $references['nodes'] ?? [];

        if (!is_array($nodes)) {
            return [];
        }

        $normalized = [];

        foreach ($nodes as $node) {
            if (!is_array($node) || empty($node['id'])) {
                continue;
            }

            $fields = [];

            foreach ($node['fields'] ?? [] as $field) {
                if (!is_array($field) || empty($field['key'])) {
                    continue;
                }

                $fields[$field['key']] = $field['value'] ?? null;
            }

            $normalized[] = [
                'id' => $node['id'],
                'type' => $node['type'] ?? null,
                'fields' => $fields,
            ];
        }

        return $normalized;
    }

    /**
     * Build a flat metadata map keyed by metafield key.
     *
     * @param array<int, array{namespace: string|null, key: string, value: mixed}> $metafields
     * @return array<string, mixed>|null
     */
    private static function buildMetadataMap(array $metafields): ?array
    {
        if (empty($metafields)) {
            return null;
        }

        $metadata = [];

        foreach ($metafields as $metafield) {
            $key = $metafield['key'];
            $namespace = $metafield['namespace'];
            $value = $metafield['value'];

            $metadata[$key] = $value;

            if ($namespace && !str_contains($key, '.')) {
                $metadata["{$namespace}.{$key}"] = $value;
            }
        }

        return $metadata;
    }
}
