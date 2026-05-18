<?php

declare(strict_types=1);

namespace App\Jobs\Sales;

use App\Contracts\Services\AI\AnalyticsServiceInterface;
use App\Contracts\Services\Sales\LeadCaptureServiceInterface;
use App\Mail\AbandonRecoveryMail;
use App\Models\AiConversation;
use App\Models\AiLead;
use App\Models\AiMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends the abandon-recovery email for a single captured lead.
 *
 * Dispatched from ChatbotService::endSession with a 30-minute delay. Idempotent
 * by design — re-runs check lead status and skip if anything other than
 * `new` is observed, so a duplicate dispatch (or manual retry) cannot send
 * the email twice.
 *
 * Queue: `recovery` on the Redis connection. tries/timeout/backoff follow
 * Laravel queue best-practices: retry_after (config) must exceed timeout.
 */
class SendAbandonRecoveryEmailJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function __construct(
        public readonly int $leadId,
    ) {}

    public function handle(
        LeadCaptureServiceInterface $leads,
        AnalyticsServiceInterface $analytics,
    ): void {
        $lead = AiLead::find($this->leadId);
        if ($lead === null) {
            Log::channel('ai')->info('AbandonRecovery: lead not found, skipping', [
                'lead_id' => $this->leadId,
            ]);

            return;
        }

        // Idempotency gate — only `new` leads with cart items qualify.
        if ($lead->status !== AiLead::STATUS_NEW) {
            Log::channel('ai')->info('AbandonRecovery: lead no longer new, skipping', [
                'lead_id' => $lead->id,
                'status' => $lead->status,
            ]);

            return;
        }

        if (! $lead->hasCartItems()) {
            Log::channel('ai')->info('AbandonRecovery: lead has no cart items, skipping', [
                'lead_id' => $lead->id,
            ]);

            return;
        }

        $conversation = AiConversation::query()
            ->where('session_id', $lead->session_id)
            ->first();

        $excerpt = $this->buildChatExcerpt($conversation);
        $cartUrl = sprintf('https://%s/cart', $lead->shop_domain);
        $shopName = $this->humaniseShopName($lead->shop_domain);

        try {
            Mail::to($lead->email)->send(new AbandonRecoveryMail(
                lead: $lead,
                chatExcerpt: $excerpt,
                cartUrl: $cartUrl,
                shopName: $shopName,
            ));
        } catch (Throwable $e) {
            // Don't swallow — let the queue retry per $backoff.
            Log::channel('error')->error('AbandonRecovery: mail send failed', [
                'lead_id' => $lead->id,
                'attempt' => $this->attempts(),
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }

        $leads->updateStatus((int) $lead->id, AiLead::STATUS_RECOVERY_SENT);

        // Funnel hook — Step 9 will swap this for StoreConversionEventJob.
        try {
            $analytics->record('abandon_recovery_sent', $lead->session_id, [
                'event_type' => 'abandon_recovery_sent',
                'shop_domain' => $lead->shop_domain,
                'lead_id' => $lead->id,
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        Log::channel('ai')->info('AbandonRecovery: email sent', [
            'lead_id' => $lead->id,
            'session_id' => $lead->session_id,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('error')->error('SendAbandonRecoveryEmailJob failed permanently', [
            'lead_id' => $this->leadId,
            'error' => $exception->getMessage(),
        ]);
    }

    /**
     * Build the "From our chat earlier" preview — last 3 user/assistant
     * messages, oldest first. Truncated to 200 chars each to keep the
     * email body sensible.
     *
     * @return array<int, array{role: string, message: string}>
     */
    private function buildChatExcerpt(?AiConversation $conversation): array
    {
        if ($conversation === null) {
            return [];
        }

        $latest = $conversation->messages()
            ->whereIn('role', [AiMessage::ROLE_USER, AiMessage::ROLE_ASSISTANT])
            ->latest('id')
            ->limit(3)
            ->get(['role', 'message']);

        return $latest
            ->reverse()
            ->values()
            ->map(fn (AiMessage $m): array => [
                'role' => (string) $m->role,
                'message' => mb_strimwidth((string) $m->message, 0, 200, '…'),
            ])
            ->all();
    }

    private function humaniseShopName(string $shopDomain): string
    {
        // demo.myshopify.com -> Demo
        $first = strtok($shopDomain, '.');

        return $first === false || $first === '' ? $shopDomain : ucfirst($first);
    }
}
