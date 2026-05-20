<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\Services\AI\AnalyticsServiceInterface;
use App\Contracts\Services\AI\ConversationServiceInterface;
use App\Contracts\Services\AI\EscalationServiceInterface;
use App\Jobs\AI\NotifyEscalationJob;
use App\Models\AiConversation;
use App\Services\Base\BaseService;

/**
 * Marks a conversation as escalated and dispatches the human handoff job
 * (email + optional Slack webhook). Used both by explicit user-triggered
 * escalations and by the auto-trigger keywords configured in chatbot.php.
 */
class EscalationService extends BaseService implements EscalationServiceInterface
{
    public function __construct(
        private readonly ConversationServiceInterface $conversations,
        private readonly AnalyticsServiceInterface $analytics,
    ) {
        parent::__construct();
    }

    public function trigger(AiConversation $conversation, string $reason, ?string $customerEmail = null): void
    {
        $this->conversations->escalate($conversation);

        NotifyEscalationJob::dispatch($conversation->id, $reason, $customerEmail)
            ->onConnection((string) config('chatbot.queue.connection', 'redis'))
            ->onQueue((string) config('chatbot.queue.name', 'ai'));

        $this->analytics->record(AnalyticsServiceInterface::EVENT_ESCALATION_TRIGGERED, $conversation->session_id, [
            'reason' => $reason,
            'customer_email' => $customerEmail,
        ]);

        $this->logInfo('Conversation escalated', [
            'session_id' => $conversation->session_id,
            'reason' => $reason,
        ], 'ai');
    }
}
