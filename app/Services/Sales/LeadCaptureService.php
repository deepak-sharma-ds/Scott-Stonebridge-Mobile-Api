<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Contracts\Services\Sales\LeadCaptureServiceInterface;
use App\Models\AiLead;
use App\Services\Base\BaseService;
use Throwable;

/**
 * Persistence layer for mid-chat email captures. All work goes through
 * Eloquent so the unique (session_id, email) index does the duplicate
 * deduping at the DB level — service-level read-then-write would race.
 *
 * Status transitions (SendAbandonRecoveryEmailJob, Shopify webhook, etc.)
 * route through updateStatus().
 */
class LeadCaptureService extends BaseService implements LeadCaptureServiceInterface
{
    public function capture(
        string $sessionId,
        string $shopDomain,
        string $email,
        ?string $name = null,
        array $cartSnapshot = [],
        string $source = AiLead::SOURCE_MANUAL_INPUT,
        ?string $issueSummary = null,
    ): AiLead|false {
        $sessionId = trim($sessionId);
        $email = mb_strtolower(trim($email));
        $shopDomain = trim($shopDomain);

        if ($sessionId === '' || $email === '' || $shopDomain === '') {
            return false;
        }

        // Short-circuit before hitting the unique index — keeps the happy
        // duplicate path cheap and avoids an exception in the log.
        $existing = AiLead::query()
            ->where('session_id', $sessionId)
            ->where('email', $email)
            ->first();

        if ($existing !== null) {
            return false;
        }

        try {
            $lead = AiLead::create([
                'session_id' => $sessionId,
                'shop_domain' => $shopDomain,
                'email' => $email,
                'name' => $name !== null && $name !== '' ? $name : null,
                'issue_summary' => $issueSummary !== null && $issueSummary !== '' ? $issueSummary : null,
                'cart_snapshot_json' => $cartSnapshot === [] ? null : $cartSnapshot,
                'source' => $source,
                'status' => AiLead::STATUS_NEW,
            ]);
        } catch (Throwable $e) {
            // Race: another request inserted the same (session, email) pair
            // between our SELECT and our INSERT. Treat as duplicate.
            $this->logWarning('Lead capture race or constraint violation', [
                'session_id' => $sessionId,
                'email' => $email,
                'error' => $e->getMessage(),
            ], 'ai');

            return false;
        }

        $this->logInfo('AI lead captured', [
            'lead_id' => $lead->id,
            'session_id' => $sessionId,
            'shop_domain' => $shopDomain,
            'source' => $source,
            'has_cart' => $cartSnapshot !== [],
        ], 'ai');

        return $lead;
    }

    public function isCaptured(string $sessionId): bool
    {
        if ($sessionId === '') {
            return false;
        }

        return AiLead::query()->forSession($sessionId)->exists();
    }

    public function updateStatus(int $leadId, string $status): void
    {
        if ($leadId <= 0) {
            return;
        }

        $allowed = [
            AiLead::STATUS_NEW,
            AiLead::STATUS_RECOVERY_SENT,
            AiLead::STATUS_CONVERTED,
            AiLead::STATUS_UNSUBSCRIBED,
        ];

        if (! in_array($status, $allowed, true)) {
            $this->logWarning('Refusing unknown lead status update', [
                'lead_id' => $leadId,
                'status' => $status,
            ], 'ai');

            return;
        }

        // Only stamp recovery_sent_at on transitions into recovery_sent.
        // Other transitions preserve the original timestamp so the funnel
        // can still attribute assisted conversions later.
        $update = ['status' => $status];
        if ($status === AiLead::STATUS_RECOVERY_SENT) {
            $update['recovery_sent_at'] = now();
        }

        AiLead::query()->whereKey($leadId)->update($update);
    }
}
