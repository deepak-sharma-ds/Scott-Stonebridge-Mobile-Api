<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs\Sales;

use App\Contracts\Services\AI\AnalyticsServiceInterface;
use App\Contracts\Services\Sales\LeadCaptureServiceInterface;
use App\Jobs\AI\StoreAnalyticsJob;
use App\Jobs\Sales\SendAbandonRecoveryEmailJob;
use App\Mail\AbandonRecoveryMail;
use App\Models\AiConversation;
use App\Models\AiLead;
use App\Models\AiMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Unit coverage for SendAbandonRecoveryEmailJob.
 *
 * Job is idempotent — re-running for a lead already in recovery_sent must
 * not resend. Mail::fake + Queue::fake keep the test boundary tight.
 */
class SendAbandonRecoveryEmailJobTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_sends_email_updates_status_and_dispatches_analytics(): void
    {
        Mail::fake();
        Queue::fake([StoreAnalyticsJob::class]);

        $conversation = AiConversation::factory()->create();
        AiMessage::factory()->for($conversation, 'conversation')->user()->create(['message' => 'Is it waterproof?']);
        AiMessage::factory()->for($conversation, 'conversation')->assistant()->create(['message' => 'Yes, IP67 rated.']);

        $lead = AiLead::factory()
            ->forSession($conversation->session_id)
            ->withCart(itemCount: 1, totalPrice: 29.99)
            ->create([
                'shop_domain' => $conversation->shop_domain,
                'email' => 'recover@example.com',
            ]);

        (new SendAbandonRecoveryEmailJob((int) $lead->id))->handle(
            app(LeadCaptureServiceInterface::class),
            app(AnalyticsServiceInterface::class),
        );

        Mail::assertSent(
            AbandonRecoveryMail::class,
            fn (AbandonRecoveryMail $mail): bool => $mail->hasTo('recover@example.com')
                && $mail->lead->id === $lead->id
                && count($mail->chatExcerpt) === 2
        );

        $this->assertSame(AiLead::STATUS_RECOVERY_SENT, $lead->fresh()->status);
        $this->assertNotNull($lead->fresh()->recovery_sent_at);

        Queue::assertPushed(
            StoreAnalyticsJob::class,
            fn (StoreAnalyticsJob $job): bool => $job->event === 'abandon_recovery_sent'
                && $job->sessionId === $lead->session_id
        );
    }

    public function test_handle_skips_when_lead_missing(): void
    {
        Mail::fake();

        (new SendAbandonRecoveryEmailJob(999_999))->handle(
            app(LeadCaptureServiceInterface::class),
            app(AnalyticsServiceInterface::class),
        );

        Mail::assertNothingSent();
    }

    public function test_handle_skips_when_status_already_recovery_sent(): void
    {
        Mail::fake();
        Queue::fake();

        $conversation = AiConversation::factory()->create();
        $lead = AiLead::factory()
            ->forSession($conversation->session_id)
            ->withCart()
            ->recoverySent()
            ->create(['shop_domain' => $conversation->shop_domain]);

        (new SendAbandonRecoveryEmailJob((int) $lead->id))->handle(
            app(LeadCaptureServiceInterface::class),
            app(AnalyticsServiceInterface::class),
        );

        Mail::assertNothingSent();
        Queue::assertNotPushed(StoreAnalyticsJob::class);
    }

    public function test_handle_skips_when_cart_empty(): void
    {
        Mail::fake();
        Queue::fake();

        $conversation = AiConversation::factory()->create();
        $lead = AiLead::factory()
            ->forSession($conversation->session_id)
            ->create([
                'shop_domain' => $conversation->shop_domain,
                'cart_snapshot_json' => null,
            ]);

        (new SendAbandonRecoveryEmailJob((int) $lead->id))->handle(
            app(LeadCaptureServiceInterface::class),
            app(AnalyticsServiceInterface::class),
        );

        Mail::assertNothingSent();
        Queue::assertNotPushed(StoreAnalyticsJob::class);
    }
}
