<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\Shopify\Webhooks\WebhookInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Shopify webhooks for Appointment Booking.
 * Persists raw events asynchronously via WebhookInbox and acknowledges immediately.
 */
class ShopifyBookingWebhookController extends Controller
{
    public function __construct(private readonly WebhookInbox $inbox) {}

    /**
     * Handle Shopify orders/paid for appointment booking.
     */
    public function handlePaid(Request $request): JsonResponse
    {
        return $this->inbox->receive($request, 'booking.paid', 'orders/paid');
    }

    /**
     * Handle Shopify orders/cancelled for appointment booking.
     */
    public function handleCancelled(Request $request): JsonResponse
    {
        return $this->inbox->receive($request, 'booking.cancelled', 'orders/cancelled');
    }
}
