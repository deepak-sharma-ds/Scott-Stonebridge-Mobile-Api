<?php

declare(strict_types=1);

namespace App\Contracts\Services\AI;

use App\Models\AiConversation;

interface EscalationServiceInterface
{
    /**
     * Hand off to human support. Marks conversation status + dispatches a
     * NotifyEscalationJob (email + optional Slack).
     */
    public function trigger(AiConversation $conversation, string $reason, ?string $customerEmail = null): void;
}
