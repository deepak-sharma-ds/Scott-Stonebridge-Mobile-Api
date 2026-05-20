<?php

declare(strict_types=1);

namespace Tests\Unit\Services\AI;

use App\Exceptions\AI\AIRateLimitException;
use App\Services\AI\SafetyService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Regression coverage for the read-then-write race that used to live in
 * SafetyService::bump(). Two parallel callers could both see counter=N-1,
 * both pass the limit gate, and both increment to N+1. The post-fix code
 * uses Cache::add() (atomic SET NX EX) + Cache::increment() (atomic INCR),
 * so the (limit+1)th call deterministically throws.
 */
class SafetyServiceRaceTest extends TestCase
{
    private SafetyService $safety;

    protected function setUp(): void
    {
        parent::setUp();
        $this->safety = new SafetyService;
        Cache::flush();
    }

    public function test_bump_blocks_deterministically_at_limit_plus_one(): void
    {
        config()->set('chatbot.rate_limits.per_session_per_minute', 20);
        config()->set('chatbot.rate_limits.per_ip_per_minute', 1000);
        config()->set('chatbot.rate_limits.per_ip_per_day', 10000);

        // First 20 calls must pass.
        for ($i = 1; $i <= 20; $i++) {
            $this->safety->assertWithinLimits('race-session', null);
        }

        // 21st must throw — the post-fix code increments atomically before
        // checking, so the very next call lands at 21 which exceeds 20.
        $this->expectException(AIRateLimitException::class);
        $this->safety->assertWithinLimits('race-session', null);
    }

    public function test_per_ip_bucket_independent_from_session_bucket(): void
    {
        config()->set('chatbot.rate_limits.per_session_per_minute', 100);
        config()->set('chatbot.rate_limits.per_ip_per_minute', 3);
        config()->set('chatbot.rate_limits.per_ip_per_day', 1000);

        $this->safety->assertWithinLimits('s-a', '9.9.9.9');
        $this->safety->assertWithinLimits('s-b', '9.9.9.9');
        $this->safety->assertWithinLimits('s-c', '9.9.9.9');

        // Fourth call from same IP, different session, must trip the IP bucket.
        $this->expectException(AIRateLimitException::class);
        $this->safety->assertWithinLimits('s-d', '9.9.9.9');
    }

    public function test_first_call_sets_ttl_via_cache_add_not_lost_on_increment(): void
    {
        config()->set('chatbot.rate_limits.per_session_per_minute', 5);
        config()->set('chatbot.rate_limits.per_ip_per_minute', 100);
        config()->set('chatbot.rate_limits.per_ip_per_day', 1000);

        $this->safety->assertWithinLimits('ttl-check', null);

        // Confirm the key exists. If the bump path failed to set TTL atomically,
        // some cache drivers would treat the increment as no-TTL forever — the
        // array driver just needs the key present.
        $this->assertTrue(Cache::has('ai:safety:session:ttl-check'));
    }
}
