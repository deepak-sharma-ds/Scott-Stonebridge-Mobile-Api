<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs\Sales;

use App\Contracts\Services\AI\AnalyticsServiceInterface;
use App\Contracts\Services\Sales\LeadCaptureServiceInterface;
use App\Jobs\Sales\SendAbandonRecoveryEmailJob;
use App\Mail\AbandonRecoveryMail;
use App\Models\AiConversation;
use App\Models\AiLead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Regression coverage for the wide gap between idempotency check and
 * Mail::send in SendAbandonRecoveryEmailJob. Two parallel job dispatches
 * for the same lead used to both pass the `status !== STATUS_NEW` gate
 * and both send the recovery email. The post-fix code claims the
 * status transition atomically via a conditional UPDATE — only the first
 * dispatch wins, the second sees `claimed === 0` and no-ops.
 *
 * Also covers the new rollback path: when Mail::send throws, the claim
 * must be released so the queue retry can resend.
 */
class AbandonRecoveryConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_duplicate_dispatch_sends_exactly_one_recovery_email(): void
    {
        Mail::fake();

        $conversation = AiConversation::factory()->create();
        $lead = AiLead::factory()
            ->forSession($conversation->session_id)
            ->withCart(itemCount: 2, totalPrice: 49.99)
            ->create([
                'shop_domain' => 'demo.myshopify.com',
                'email' => 'race@example.com',
                'status' => AiLead::STATUS_NEW,
            ]);

        // Two synchronous dispatches mirror the duplicate-worker scenario.
        (new SendAbandonRecoveryEmailJob($lead->id))->handle(
            app(LeadCaptureServiceInterface::class),
            app(AnalyticsServiceInterface::class),
        );
        (new SendAbandonRecoveryEmailJob($lead->id))->handle(
            app(LeadCaptureServiceInterface::class),
            app(AnalyticsServiceInterface::class),
        );

        Mail::assertSent(AbandonRecoveryMail::class, 1);

        $lead->refresh();
        $this->assertSame(AiLead::STATUS_RECOVERY_SENT, $lead->status);
        $this->assertNotNull($lead->recovery_sent_at);
    }

    public function test_mail_send_failure_rolls_back_status_for_retry(): void
    {
        $conversation = AiConversation::factory()->create();
        $lead = AiLead::factory()
            ->forSession($conversation->session_id)
            ->withCart(itemCount: 1, totalPrice: 19.99)
            ->create([
                'shop_domain' => 'demo.myshopify.com',
                'email' => 'rollback@example.com',
                'status' => AiLead::STATUS_NEW,
            ]);

        // Force Mail::send to throw on first run.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP down'));

        try {
            (new SendAbandonRecoveryEmailJob($lead->id))->handle(
                app(LeadCaptureServiceInterface::class),
                app(AnalyticsServiceInterface::class),
            );
            $this->fail('Expected mail failure to propagate so the queue can retry.');
        } catch (\RuntimeException $e) {
            $this->assertSame('SMTP down', $e->getMessage());
        }

        $lead->refresh();
        $this->assertSame(AiLead::STATUS_NEW, $lead->status, 'Status must roll back to NEW for queue retry.');
        $this->assertNull($lead->recovery_sent_at, 'Recovery timestamp must be cleared on rollback.');
    }

    public function test_lead_with_no_cart_skips_without_claiming_status(): void
    {
        Mail::fake();

        $conversation = AiConversation::factory()->create();
        $lead = AiLead::factory()
            ->forSession($conversation->session_id)
            ->create([
                'shop_domain' => 'demo.myshopify.com',
                'email' => 'nocart@example.com',
                'status' => AiLead::STATUS_NEW,
                'cart_snapshot_json' => null,
            ]);

        (new SendAbandonRecoveryEmailJob($lead->id))->handle(
            app(LeadCaptureServiceInterface::class),
            app(AnalyticsServiceInterface::class),
        );

        Mail::assertNothingSent();

        $lead->refresh();
        $this->assertSame(AiLead::STATUS_NEW, $lead->status, 'No-cart lead must stay NEW so future cart attachment can recover it.');
    }
}
