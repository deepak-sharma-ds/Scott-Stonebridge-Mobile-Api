<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\Services\AI\OrderTrackingServiceInterface;
use App\Contracts\Shopify\AdminApiClientInterface;
use App\DTOs\AI\OrderTrackingDTO;
use App\Exceptions\AI\OrderNotFoundException;
use App\Services\Base\BaseService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Looks up a single order via Shopify Admin GraphQL using the public-safe
 * (order_number, email) pair. Caches the result for `chatbot.context.cache_ttl`
 * seconds so a customer refreshing their tracking widget doesn't burn admin
 * API budget. Cache key is hashed on lowercase email so casing tweaks land
 * on the same record.
 */
class OrderTrackingService extends BaseService implements OrderTrackingServiceInterface
{
    private const GRAPHQL_PATH = 'admin/orders/get_order_by_name';

    public function __construct(
        private readonly AdminApiClientInterface $admin,
    ) {
        parent::__construct();
    }

    public function track(string $shopDomain, string $orderNumber, string $email): OrderTrackingDTO
    {
        $shopDomain = trim($shopDomain);
        $orderNumber = ltrim(trim($orderNumber), '#');
        $email = strtolower(trim($email));

        if ($shopDomain === '' || $orderNumber === '' || $email === '') {
            throw new OrderNotFoundException('Order not found.', [
                'reason' => 'invalid_input',
            ]);
        }

        $startedAt = microtime(true);
        $node = $this->fetchOrderNode($shopDomain, $orderNumber, $email);

        if ($node === null) {
            $this->logWarning('Order not found', [
                'shop' => $shopDomain,
                'order_number' => $orderNumber,
                'reason' => 'no_match',
            ], 'ai');

            throw new OrderNotFoundException('Order not found.', [
                'reason' => 'no_match',
            ]);
        }

        // Defense-in-depth — Shopify search can return loose matches for the
        // email keyword. Re-check exact equality before handing the order to
        // the caller. We never disclose whether the order exists vs. email
        // mismatch — same 404 envelope either way.
        $returnedEmail = strtolower(trim((string) ($node['email'] ?? '')));
        if ($returnedEmail !== $email) {
            $this->logWarning('Order not found', [
                'shop' => $shopDomain,
                'order_number' => $orderNumber,
                'reason' => 'email_mismatch',
            ], 'ai');

            throw new OrderNotFoundException('Order not found.', [
                'reason' => 'email_mismatch',
            ]);
        }

        $dto = OrderTrackingDTO::fromShopifyNode($node);

        $this->logInfo('Order tracked', [
            'shop' => $shopDomain,
            'order_number' => $dto->orderNumber,
            'status' => $dto->status,
            'latency_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ], 'ai');

        return $dto;
    }

    /**
     * Cache-lock wrapped Shopify Admin query. Mirrors the stampede protection
     * already used in UpsellService::recommendationsForProduct so concurrent
     * widget refreshes for the same order collapse into a single Admin call.
     *
     * @return array<string, mixed>|null Order node, or null when Shopify returned 0 edges.
     */
    private function fetchOrderNode(string $shopDomain, string $orderNumber, string $email): ?array
    {
        $ttl = (int) config('chatbot.context.cache_ttl', 180);
        $key = sprintf('ai:order:%s', md5($shopDomain.':'.$orderNumber.':'.$email));

        // Fast path — happy cache hit, no lock needed.
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        // Negative cache: explicit "no match" marker we stash so a 404 doesn't
        // re-hit Shopify Admin within the TTL window.
        if ($cached === '__missing__') {
            return null;
        }

        $lock = Cache::lock($key.':lock', 5);

        try {
            $lock->block(2);

            // Re-check inside the lock — previous winner may have populated.
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
            if ($cached === '__missing__') {
                return null;
            }

            $node = $this->callShopifyAdmin($orderNumber, $email);

            if ($node === null) {
                Cache::put($key, '__missing__', $ttl);

                return null;
            }

            Cache::put($key, $node, $ttl);

            return $node;
        } catch (LockTimeoutException) {
            // Lock contention beat the 2s wait. Fall through to a direct
            // uncached fetch — better than blocking the user response.
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
            if ($cached === '__missing__') {
                return null;
            }

            return $this->callShopifyAdmin($orderNumber, $email);
        } finally {
            optional($lock)->release();
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function callShopifyAdmin(string $orderNumber, string $email): ?array
    {
        // Shopify Admin order-search syntax: `name:#1234 email:foo@bar.com`.
        // Quoting the email defends against addresses containing reserved
        // characters that would otherwise break the search expression.
        $searchExpr = sprintf('name:#%s email:"%s"', $orderNumber, $email);

        try {
            $response = $this->admin->query(self::GRAPHQL_PATH, [
                'query' => $searchExpr,
            ]);
        } catch (Throwable $e) {
            $this->logWarning('Shopify Admin order lookup failed', [
                'order_number' => $orderNumber,
                'error' => $e->getMessage(),
            ], 'ai');

            // Surface as not-found so the controller renders 404 instead of
            // 500. Customer-facing widget should not expose Admin internals.
            return null;
        }

        $edges = $response['data']['orders']['edges'] ?? null;
        if (! is_array($edges) || $edges === []) {
            return null;
        }

        $node = $edges[0]['node'] ?? null;

        return is_array($node) ? $node : null;
    }
}
