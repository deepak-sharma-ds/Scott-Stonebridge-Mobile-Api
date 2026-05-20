<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\AI;

use App\Contracts\Services\AI\OrderTrackingServiceInterface;
use App\Exceptions\AI\AIException;
use App\Exceptions\AI\OrderNotFoundException;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Requests\AI\OrderTrackRequest;
use Illuminate\Http\JsonResponse;
use Throwable;

/**
 * GET /api/v1/ai/orders/track
 *
 * Public-by-email-match — caller supplies the order number AND the matching
 * email. The OrderTrackingService does a Shopify Admin lookup; mismatches
 * fall through to the same 404 envelope so the endpoint cannot be used as
 * an order-enumeration oracle. Rate-limited via the `ai-order-track`
 * limiter (10/min by session_id, falls back to IP).
 */
class OrderTrackingController extends BaseApiController
{
    public function __construct(
        private readonly OrderTrackingServiceInterface $orderTracking,
    ) {}

    public function show(OrderTrackRequest $request): JsonResponse
    {
        $data = $request->validated();

        try {
            $dto = $this->orderTracking->track(
                shopDomain: (string) $data['shop_domain'],
                orderNumber: (string) $data['order_number'],
                email: (string) $data['email'],
            );
        } catch (OrderNotFoundException $e) {
            return $this->error(
                $e->getMessage(),
                [],
                ['error_code' => $e->errorCode()],
                $e->httpStatus(),
            );
        } catch (AIException $e) {
            return $this->error(
                $e->getMessage(),
                $e->errorContext(),
                ['error_code' => $e->errorCode()],
                $e->httpStatus(),
            );
        } catch (Throwable $e) {
            report($e);

            return $this->error(
                'Order tracking failed.',
                [],
                ['error_code' => 'order_track_failed'],
                500,
            );
        }

        return $this->success('Order found.', $dto->toArray());
    }
}
