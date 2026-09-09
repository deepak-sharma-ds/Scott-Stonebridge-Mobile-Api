<?php

namespace App\Http\Controllers\Api\V1\Push\Concerns;

use Illuminate\Http\Request;

/**
 * Resolves the authenticated Shopify customer id + email from the request
 * context populated by the shopify.auth middleware. Shared by every push
 * controller that needs to scope data to the calling customer.
 */
trait ResolvesAuthenticatedCustomer
{
    /**
     * @return array{id: int|null, email: string|null}
     */
    protected function customer(Request $request): array
    {
        $data = (array) $request->input('shopify_customer_data', []);
        $rawId = $request->input('shopify_customer_id') ?? ($data['id'] ?? null);

        return [
            'id' => $this->normalizeCustomerId($rawId),
            'email' => isset($data['email']) ? strtolower(trim((string) $data['email'])) : null,
        ];
    }

    /**
     * Shopify customer ids may arrive as a GID (gid://shopify/Customer/123);
     * reduce to the numeric id stored on device_tokens.
     */
    protected function normalizeCustomerId(mixed $rawId): ?int
    {
        if ($rawId === null) {
            return null;
        }

        if (is_numeric($rawId)) {
            return (int) $rawId;
        }

        if (is_string($rawId) && preg_match('/(\d+)$/', $rawId, $matches)) {
            return (int) $matches[1];
        }

        return null;
    }
}
