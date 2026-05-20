<?php

declare(strict_types=1);

namespace App\Contracts\Services\AI;

interface AnalyticsServiceInterface
{
    public const EVENT_SESSION_STARTED = 'session.started';

    public const EVENT_MESSAGE_SENT = 'message.sent';

    public const EVENT_MESSAGE_RECEIVED = 'message.received';

    public const EVENT_INTENT_DETECTED = 'intent.detected';

    public const EVENT_RECOMMENDATION_SERVED = 'recommendation.served';

    public const EVENT_SAFETY_BLOCKED = 'safety.blocked';

    public const EVENT_AI_ERROR = 'ai.error';

    public const EVENT_ESCALATION_TRIGGERED = 'escalation.triggered';

    public const EVENT_SESSION_ENDED = 'session.ended';

    /**
     * Record an event asynchronously via StoreAnalyticsJob on the `ai` queue.
     *
     * @param  array<string, mixed>  $payload
     */
    public function record(string $event, string $sessionId, array $payload = []): void;
}
