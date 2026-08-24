<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\DTOs\Chat\CustomerContextDTO;
use App\Models\AiCustomerSession;
use App\Services\AI\MCP\CustomerAccountGraphClient;
use App\Services\Shopify\AdminService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * E1 — builds a lightweight, privacy-safe order summary for a signed-in
 * customer so the assistant can personalise greetings and recommendations.
 *
 * Deliberately read-only on auth: it uses the customer's access token ONLY
 * when still valid and NEVER triggers a refresh (a single-use refresh token
 * must not be spent on a cosmetic feature — the real order tools handle
 * refresh when the customer actually asks about orders). If the token is
 * missing or expired the summary is simply skipped.
 *
 * No PII (name / email) and no line items are fetched — only order number,
 * total, and date.
 */
class CustomerPersonalizationService
{
    private ?ChatbotConfigRepository $chatbotConfig;

    public function __construct(
        private readonly CustomerAccountGraphClient $graph,
        ?ChatbotConfigRepository $chatbotConfig = null,
    ) {
        $this->chatbotConfig = $chatbotConfig;
    }

    /**
     * Lazily resolved so the existing 1-arg test constructions keep working
     * without modification — same pattern as ToolExecutor.
     */
    private function chatbotConfig(): ChatbotConfigRepository
    {
        return $this->chatbotConfig ??= app(ChatbotConfigRepository::class);
    }

    /**
     * @return array{order_count:int, recent_orders:list<array{number:string, total:?string, currency:?string, date:?string}>}|null
     */
    public function summaryFor(string $sessionId, string $shopDomain, bool $isGuest = false, ?CustomerContextDTO $customer = null): ?array
    {
        if ($isGuest || $shopDomain === '') {
            return null;
        }

        $row = $sessionId !== '' ? AiCustomerSession::query()->where('session_id', $sessionId)->first() : null;
        $token = ($row !== null && ! $row->isExpired()) ? (string) $row->customer_access_token : '';

        // 1. If valid Customer Account API token exists:
        if ($token !== '') {
            $cacheKey = sprintf('ai:session:%s:cust_summary', $sessionId);

            return Cache::remember($cacheKey, $this->chatbotConfig()->personalizationCacheTtlSeconds(), function () use ($shopDomain, $token): ?array {
                try {
                    $data = $this->graph->query($shopDomain, $token, $this->recentOrdersQuery(), [
                        'first' => $this->chatbotConfig()->personalizationRecentOrdersLimit(),
                    ]);

                    return $this->mapSummary($data);
                } catch (Throwable $e) {
                    // Personalisation is best-effort — never break the chat turn.
                    Log::channel('ai')->info('personalization.fetch_failed', [
                        'shop_domain' => $shopDomain,
                        'error' => $e->getMessage(),
                    ]);

                    return null;
                }
            });
        }

        // 2. If storefront logged-in customer:
        if ($customer !== null && ! $customer->isGuest()) {
            $identifier = $customer->customerId ?? $customer->email ?? $sessionId;
            $cacheKey = sprintf('ai:customer:%s:cust_summary', $identifier);

            return Cache::remember($cacheKey, $this->chatbotConfig()->personalizationCacheTtlSeconds(), function () use ($customer): ?array {
                try {
                    $admin = app(AdminService::class);
                    $first = $this->chatbotConfig()->personalizationRecentOrdersLimit();

                    if ($customer->customerId !== null) {
                        $bareId = preg_replace('~^gid://shopify/Customer/~', '', $customer->customerId);
                        $query = <<<'GRAPHQL'
                        query PersonalizationCustomerOrders($customerId: ID!, $first: Int!) {
                          customer(id: $customerId) {
                            orders(first: $first, sortKey: PROCESSED_AT, reverse: true) {
                              edges {
                                node {
                                  name
                                  processedAt
                                  totalPriceSet {
                                    presentmentMoney { amount currencyCode }
                                    shopMoney { amount currencyCode }
                                  }
                                }
                              }
                            }
                          }
                        }
                        GRAPHQL;

                        $resp = $admin->request($query, [
                            'customerId' => "gid://shopify/Customer/{$bareId}",
                            'first' => $first,
                        ]);
                    } else {
                        $query = <<<'GRAPHQL'
                        query PersonalizationOrdersByEmail($query: String!, $first: Int!) {
                          orders(first: $first, query: $query, sortKey: PROCESSED_AT, reverse: true) {
                            edges {
                              node {
                                name
                                processedAt
                                totalPriceSet {
                                  presentmentMoney { amount currencyCode }
                                  shopMoney { amount currencyCode }
                                }
                              }
                            }
                          }
                        }
                        GRAPHQL;

                        $resp = $admin->request($query, [
                            'query' => "email:{$customer->email}",
                            'first' => $first,
                        ]);
                    }

                    $ordersData = $resp['data']['customer']['orders']['edges'] ?? $resp['data']['orders']['edges'] ?? [];
                    if (! is_array($ordersData) || $ordersData === []) {
                        return null;
                    }

                    $recent = [];
                    foreach ($ordersData as $edge) {
                        $node = $edge['node'] ?? null;
                        if (! is_array($node)) {
                            continue;
                        }
                        $name = $node['name'] ?? null;
                        if ($name === null || $name === '') {
                            continue;
                        }
                        $money = $node['totalPriceSet']['presentmentMoney'] ?? $node['totalPriceSet']['shopMoney'] ?? [];
                        $recent[] = [
                            'number' => ltrim((string) $name, '#'),
                            'total' => isset($money['amount']) ? (string) $money['amount'] : null,
                            'currency' => isset($money['currencyCode']) ? (string) $money['currencyCode'] : null,
                            'date' => isset($node['processedAt']) ? substr((string) $node['processedAt'], 0, 10) : null,
                        ];
                    }

                    if ($recent === []) {
                        return null;
                    }

                    return [
                        'order_count' => count($recent),
                        'recent_orders' => $recent,
                    ];
                } catch (Throwable $e) {
                    Log::channel('ai')->info('personalization.admin_fetch_failed', [
                        'error' => $e->getMessage(),
                    ]);

                    return null;
                }
            });
        }

        return null;
    }

    private function recentOrdersQuery(): string
    {
        return <<<'GRAPHQL'
        query RecentOrders($first: Int!) {
          customer {
            orders(first: $first, sortKey: PROCESSED_AT, reverse: true) {
              edges {
                node {
                  number
                  processedAt
                  totalPrice { amount currencyCode }
                }
              }
            }
          }
        }
        GRAPHQL;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{order_count:int, recent_orders:list<array{number:string, total:?string, currency:?string, date:?string}>}|null
     */
    private function mapSummary(array $data): ?array
    {
        $edges = $data['customer']['orders']['edges'] ?? null;
        if (! is_array($edges) || $edges === []) {
            return null;
        }

        $recent = [];
        foreach ($edges as $edge) {
            $node = $edge['node'] ?? null;
            if (! is_array($node)) {
                continue;
            }
            $number = $node['number'] ?? null;
            if ($number === null || $number === '') {
                continue;
            }
            $recent[] = [
                'number' => (string) $number,
                'total' => isset($node['totalPrice']['amount']) ? (string) $node['totalPrice']['amount'] : null,
                'currency' => isset($node['totalPrice']['currencyCode']) ? (string) $node['totalPrice']['currencyCode'] : null,
                'date' => isset($node['processedAt']) ? substr((string) $node['processedAt'], 0, 10) : null,
            ];
        }

        if ($recent === []) {
            return null;
        }

        return [
            'order_count' => count($recent),
            'recent_orders' => $recent,
        ];
    }
}
