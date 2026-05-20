<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Contracts\Shopify\AdminApiClientInterface;
use App\Exceptions\AI\OrderNotFoundException;
use App\Services\AI\OrderTrackingService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Isolated coverage of OrderTrackingService — Admin client fully mocked.
 * Verifies cache behaviour, email-mismatch defence, and the contract that
 * "no order" / "wrong email" both surface as OrderNotFoundException.
 */
class OrderTrackingServiceTest extends TestCase
{
    private const GRAPHQL_PATH = 'admin/orders/get_order_by_name';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        config(['chatbot.context.cache_ttl' => 60]);
    }

    public function test_caches_result_so_second_call_does_not_re_hit_admin(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);

        // Must be called exactly once even though we call ->track() twice.
        $client
            ->expects($this->once())
            ->method('query')
            ->with(self::GRAPHQL_PATH, $this->callback(function (array $vars): bool {
                return isset($vars['query'])
                    && str_contains($vars['query'], 'name:#1001')
                    && str_contains($vars['query'], 'email:"jane@example.com"');
            }))
            ->willReturn([
                'data' => ['orders' => ['edges' => [['node' => [
                    'id' => 'gid://shopify/Order/1',
                    'name' => '#1001',
                    'email' => 'jane@example.com',
                    'displayFulfillmentStatus' => 'IN_TRANSIT',
                    'displayFinancialStatus' => 'PAID',
                    'shippingAddress' => ['city' => 'London'],
                    'fulfillments' => [],
                ]]]]],
            ]);

        $svc = new OrderTrackingService($client);

        $first = $svc->track('demo.myshopify.com', '1001', 'jane@example.com');
        $second = $svc->track('demo.myshopify.com', '1001', 'JANE@example.com'); // case-insensitive

        $this->assertSame('1001', $first->orderNumber);
        $this->assertSame('in_transit', $first->status);
        $this->assertSame($first->orderNumber, $second->orderNumber);
    }

    public function test_throws_order_not_found_when_admin_returns_zero_edges(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client
            ->expects($this->once())
            ->method('query')
            ->willReturn(['data' => ['orders' => ['edges' => []]]]);

        $svc = new OrderTrackingService($client);

        $this->expectException(OrderNotFoundException::class);
        $svc->track('demo.myshopify.com', '404404', 'nobody@example.com');
    }

    public function test_throws_order_not_found_when_returned_email_does_not_match(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client
            ->method('query')
            ->willReturn(['data' => ['orders' => ['edges' => [['node' => [
                'id' => 'gid://shopify/Order/2',
                'name' => '#2002',
                'email' => 'someone-else@example.com',
                'displayFulfillmentStatus' => 'FULFILLED',
                'displayFinancialStatus' => 'PAID',
                'shippingAddress' => null,
                'fulfillments' => [],
            ]]]]]]);

        $svc = new OrderTrackingService($client);

        $this->expectException(OrderNotFoundException::class);
        $svc->track('demo.myshopify.com', '2002', 'wrong@example.com');
    }

    public function test_admin_client_failure_surfaces_as_order_not_found(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client
            ->method('query')
            ->willThrowException(new \RuntimeException('Shopify is down'));

        $svc = new OrderTrackingService($client);

        $this->expectException(OrderNotFoundException::class);
        $svc->track('demo.myshopify.com', '3003', 'jane@example.com');
    }

    public function test_strips_leading_hash_from_order_number_input(): void
    {
        $client = $this->createMock(AdminApiClientInterface::class);
        $client
            ->expects($this->once())
            ->method('query')
            ->with(self::GRAPHQL_PATH, $this->callback(function (array $vars): bool {
                // Input was "#1234" — search expression must use "name:#1234"
                // exactly once (not "name:##1234").
                return str_contains($vars['query'], 'name:#1234')
                    && ! str_contains($vars['query'], 'name:##');
            }))
            ->willReturn(['data' => ['orders' => ['edges' => [['node' => [
                'id' => 'gid://shopify/Order/3',
                'name' => '#1234',
                'email' => 'jane@example.com',
                'displayFulfillmentStatus' => 'IN_TRANSIT',
                'displayFinancialStatus' => 'PAID',
                'shippingAddress' => null,
                'fulfillments' => [],
            ]]]]]]);

        $svc = new OrderTrackingService($client);
        $dto = $svc->track('demo.myshopify.com', '#1234', 'jane@example.com');

        $this->assertSame('1234', $dto->orderNumber);
    }
}
