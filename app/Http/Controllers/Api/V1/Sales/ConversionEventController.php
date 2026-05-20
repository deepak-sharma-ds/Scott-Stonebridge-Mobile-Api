<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Base\BaseApiController;
use App\Http\Requests\Sales\StoreConversionEventRequest;
use App\Jobs\Sales\StoreConversionEventJob;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * Conversion event ingestion.
 *
 *   POST /api/v1/ai/analytics/event  -> store
 *
 * Always returns 200 — analytics must never block UX. Dispatch failures
 * are reported but swallowed.
 */
class ConversionEventController extends BaseApiController
{
    public function store(StoreConversionEventRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            StoreConversionEventJob::dispatch(
                sessionId: (string) $data['session_id'],
                shopDomain: (string) $data['shop_domain'],
                eventType: (string) $data['event_type'],
                productId: $data['product_id'] ?? null,
                orderId: $data['order_id'] ?? null,
                revenue: isset($data['revenue']) ? (float) $data['revenue'] : null,
                metadata: (array) ($data['metadata'] ?? []),
            )
                ->onConnection((string) config('sales.queue.connection', 'redis'))
                ->onQueue((string) config('sales.queue.analytics', 'analytics'));
        } catch (Throwable $e) {
            report($e);
        }

        return $this->success('Event accepted.', ['accepted' => true]);
    }
}
