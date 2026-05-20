<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Contracts\Services\AI\AnalyticsServiceInterface;
use App\Contracts\Services\Sales\LeadCaptureServiceInterface;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Requests\Sales\CaptureLeadRequest;
use App\Http\Resources\Sales\LeadResource;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Lead capture endpoints.
 *
 *   POST /api/v1/ai/leads/capture  -> capture
 *
 * The endpoint is intentionally tolerant of duplicate submissions — the
 * service returns false rather than throwing so the storefront can retry
 * safely after a slow network. Behaviour is idempotent at the (session,
 * email) level.
 */
class LeadController extends BaseApiController
{
    public function __construct(
        private readonly LeadCaptureServiceInterface $leadCapture,
        private readonly AnalyticsServiceInterface $analytics,
    ) {}

    public function capture(CaptureLeadRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $lead = $this->leadCapture->capture(
                sessionId: (string) $data['session_id'],
                shopDomain: (string) $data['shop_domain'],
                email: (string) $data['email'],
                name: $data['name'] ?? null,
                cartSnapshot: (array) ($data['cart_snapshot'] ?? []),
                source: (string) $data['source'],
                issueSummary: $data['issue_summary'] ?? null,
            );
        } catch (Throwable $e) {
            report($e);

            return $this->error(
                'Failed to capture lead.',
                [],
                ['error_code' => 'lead_capture_failed'],
                500,
            );
        }

        if ($lead === false) {
            // Idempotent duplicate — return 200 with captured=false so the
            // client can drop its local pending state without retrying.
            return $this->success('Lead already captured for this session.', [
                'captured' => false,
                'duplicate' => true,
            ]);
        }

        // Funnel hook — Step 9 will swap the underlying job for the
        // dedicated StoreConversionEventJob without changing this call.
        try {
            $this->analytics->record('lead_captured', $lead->session_id, [
                'event_type' => 'lead_captured',
                'shop_domain' => $lead->shop_domain,
                'source' => $lead->source,
                'has_cart' => $lead->hasCartItems(),
                'lead_id' => $lead->id,
            ]);
        } catch (Throwable $e) {
            report($e);
        }

        return $this->success(
            'Lead captured.',
            (new LeadResource($lead))->toArray($request) + ['captured' => true],
            statusCode: 201,
        );
    }
}
