<?php

namespace Tests\Unit\Services\Shopify;

use App\Services\Shopify\ShippingScheduleResolver;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ShippingScheduleResolverTest extends TestCase
{
    private ShippingScheduleResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new ShippingScheduleResolver;
    }

    public function test_resolve_scheduled_at_reuses_existing_timestamp(): void
    {
        $existing = Carbon::now()->addDays(5);

        $result = $this->resolver->resolveScheduledAt($existing, false, 'email_reading');

        $this->assertTrue($existing->equalTo($result));
    }

    public function test_resolve_scheduled_at_returns_null_when_schedule_disabled(): void
    {
        config()->set('email_reading.schedule.enabled', false);

        $result = $this->resolver->resolveScheduledAt(null, false, 'email_reading');

        $this->assertNull($result);
    }

    public function test_resolve_scheduled_at_falls_within_configured_day_window(): void
    {
        config()->set('email_reading.schedule.enabled', true);
        config()->set('email_reading.schedule.min_days', 3);
        config()->set('email_reading.schedule.max_days', 7);

        $result = $this->resolver->resolveScheduledAt(null, false, 'email_reading');

        $this->assertNotNull($result);
        $this->assertTrue($result->greaterThanOrEqualTo(Carbon::now()->addDays(3)->subMinute()));
        $this->assertTrue($result->lessThanOrEqualTo(Carbon::now()->addDays(7)->addMinute()));
    }

    public function test_resolve_scheduled_at_uses_expedite_window_when_expedited(): void
    {
        config()->set('email_reading.expedite.min_hours', 1);
        config()->set('email_reading.expedite.max_hours', 24);

        $result = $this->resolver->resolveScheduledAt(null, true, 'email_reading');

        $this->assertNotNull($result);
        $this->assertTrue($result->greaterThanOrEqualTo(Carbon::now()->addHour()->subMinute()));
        $this->assertTrue($result->lessThanOrEqualTo(Carbon::now()->addHours(24)->addMinute()));
    }

    public function test_resolve_expedited_at_reuses_existing_timestamp(): void
    {
        $existing = Carbon::now()->addHours(3);

        $result = $this->resolver->resolveExpeditedAt($existing, 'email_reading');

        $this->assertTrue($existing->equalTo($result));
    }

    public function test_resolve_expedited_at_falls_within_configured_hour_window(): void
    {
        config()->set('email_reading.expedite.min_hours', 1);
        config()->set('email_reading.expedite.max_hours', 24);

        $result = $this->resolver->resolveExpeditedAt(null, 'email_reading');

        $this->assertTrue($result->greaterThanOrEqualTo(Carbon::now()->addHour()->subMinute()));
        $this->assertTrue($result->lessThanOrEqualTo(Carbon::now()->addHours(24)->addMinute()));
    }

    public function test_has_same_day_upgrade_detects_matching_shipping_line(): void
    {
        config()->set('email_reading.expedite.shipping_titles', ['same day guarantee - via email']);

        $order = [
            'shipping_lines' => [
                ['title' => 'Same Day Guarantee - Via Email', 'is_removed' => false],
            ],
        ];

        $this->assertTrue($this->resolver->hasSameDayUpgrade($order, 'email_reading'));
    }

    public function test_has_same_day_upgrade_ignores_removed_lines(): void
    {
        config()->set('email_reading.expedite.shipping_titles', ['same day guarantee - via email']);

        $order = [
            'shipping_lines' => [
                ['title' => 'Same Day Guarantee - Via Email', 'is_removed' => true],
            ],
        ];

        $this->assertFalse($this->resolver->hasSameDayUpgrade($order, 'email_reading'));
    }

    public function test_has_same_day_upgrade_returns_false_when_no_titles_configured(): void
    {
        config()->set('email_reading.expedite.shipping_titles', []);

        $order = ['shipping_lines' => [['title' => 'Anything']]];

        $this->assertFalse($this->resolver->hasSameDayUpgrade($order, 'email_reading'));
    }

    public function test_resolver_is_config_namespace_independent(): void
    {
        config()->set('email_reading.schedule.min_days', 3);
        config()->set('email_reading.schedule.max_days', 3);
        config()->set('campaign_email.schedule.min_days', 10);
        config()->set('campaign_email.schedule.max_days', 10);

        $reading = $this->resolver->resolveScheduledAt(null, false, 'email_reading');
        $campaign = $this->resolver->resolveScheduledAt(null, false, 'campaign_email');

        $this->assertTrue($reading->diffInDays(Carbon::now(), true) <= 3);
        $this->assertTrue($campaign->diffInDays(Carbon::now(), true) >= 9);
    }
}
