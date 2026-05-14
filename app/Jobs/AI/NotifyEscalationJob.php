<?php

declare(strict_types=1);

namespace App\Jobs\AI;

use App\Models\AiConversation;
use App\Models\AiMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Delivers human-handoff notifications for an escalated conversation. Sends
 * an email to the configured support address and (optionally) posts to a
 * Slack webhook. Both sinks are best-effort — failure is logged but does not
 * cause the customer's request to fail.
 */
class NotifyEscalationJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public int $backoff = 10;

    public function __construct(
        public readonly int $conversationId,
        public readonly string $reason,
        public readonly ?string $customerEmail = null,
    ) {}

    public function handle(): void
    {
        $conversation = AiConversation::find($this->conversationId);
        if ($conversation === null) {
            return;
        }

        $transcript = $conversation->messages()
            ->whereIn('role', [AiMessage::ROLE_USER, AiMessage::ROLE_ASSISTANT])
            ->orderBy('id')
            ->limit(50)
            ->get(['role', 'message'])
            ->map(fn (AiMessage $m): string => strtoupper($m->role).': '.$m->message)
            ->implode("\n");

        $supportEmail = (string) (config('chatbot.escalation.email') ?? '');
        if ($supportEmail !== '') {
            try {
                Mail::raw($this->buildEmailBody($conversation, $transcript), function ($mail) use ($supportEmail, $conversation) {
                    $mail->to($supportEmail)
                        ->subject('AI Chat escalation — session '.$conversation->session_id);
                });
            } catch (Throwable $e) {
                Log::channel('error')->error('NotifyEscalationJob: email send failed', [
                    'conversation_id' => $this->conversationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $slack = (string) (config('chatbot.escalation.slack_webhook') ?? '');
        if ($slack !== '') {
            try {
                Http::timeout(5)->post($slack, [
                    'text' => sprintf(
                        ":rotating_light: AI chat escalation\nSession: `%s`\nReason: %s\nCustomer: %s",
                        $conversation->session_id,
                        $this->reason,
                        $this->customerEmail ?? 'unknown',
                    ),
                ]);
            } catch (Throwable $e) {
                Log::channel('error')->error('NotifyEscalationJob: slack post failed', [
                    'conversation_id' => $this->conversationId,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function buildEmailBody(AiConversation $conversation, string $transcript): string
    {
        return implode("\n", [
            'Session: '.$conversation->session_id,
            'Shop: '.$conversation->shop_domain,
            'Customer: '.($this->customerEmail ?? $conversation->shopify_customer_id ?? 'guest'),
            'Reason: '.$this->reason,
            'Page: '.($conversation->page_type ?? 'unknown'),
            '',
            'Transcript:',
            $transcript,
        ]);
    }

    public function failed(Throwable $exception): void
    {
        Log::channel('error')->error('NotifyEscalationJob failed', [
            'conversation_id' => $this->conversationId,
            'error' => $exception->getMessage(),
        ]);
    }
}
