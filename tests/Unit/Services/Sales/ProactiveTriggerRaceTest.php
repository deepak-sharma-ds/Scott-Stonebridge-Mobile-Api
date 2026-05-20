<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sales;

use App\Services\Sales\ProactiveTriggerService;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Regression coverage for the previously non-atomic markFired() write.
 * Cache::put() let two parallel pageloads both pass shouldFire() and both
 * write their flag — same key won, but BOTH requests proceeded to emit the
 * trigger. The post-fix code uses Cache::add() so only the first writer in
 * the TTL window returns true.
 */
class ProactiveTriggerRaceTest extends TestCase
{
    private ProactiveTriggerService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new ProactiveTriggerService;
        Cache::flush();
    }

    public function test_first_mark_returns_true_second_returns_false(): void
    {
        $first = $this->svc->markFired('session-race', 42);
        $second = $this->svc->markFired('session-race', 42);

        $this->assertTrue($first, 'First claim must win the SET NX EX.');
        $this->assertFalse($second, 'Second claim within TTL must lose.');
    }

    public function test_mark_fired_rejects_empty_session(): void
    {
        $this->assertFalse($this->svc->markFired('', 42));
    }

    public function test_mark_fired_rejects_zero_rule_id(): void
    {
        $this->assertFalse($this->svc->markFired('session-x', 0));
    }

    public function test_different_rules_do_not_collide_on_same_session(): void
    {
        $this->assertTrue($this->svc->markFired('session-y', 1));
        $this->assertTrue($this->svc->markFired('session-y', 2));
        $this->assertFalse($this->svc->markFired('session-y', 1));
    }
}
