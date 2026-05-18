<?php

declare(strict_types=1);

namespace App\Contracts\Services\Sales;

use App\Models\AiLead;

/**
 * Persists customer leads captured mid-conversation. Returns false (does NOT
 * throw) for the duplicate (session, email) case so the controller can treat
 * re-submits as idempotent.
 */
interface LeadCaptureServiceInterface
{
    /**
     * Persist a new lead. Returns the stored model, or `false` when an
     * identical (session_id, email) pair already exists.
     *
     * @param  array<string, mixed>  $cartSnapshot
     */
    public function capture(
        string $sessionId,
        string $shopDomain,
        string $email,
        ?string $name = null,
        array $cartSnapshot = [],
        string $source = AiLead::SOURCE_MANUAL_INPUT,
        ?string $issueSummary = null,
    ): AiLead|false;

    /**
     * True when at least one lead exists for the given session_id.
     */
    public function isCaptured(string $sessionId): bool;

    /**
     * Update the status column on an existing lead.
     */
    public function updateStatus(int $leadId, string $status): void;
}
