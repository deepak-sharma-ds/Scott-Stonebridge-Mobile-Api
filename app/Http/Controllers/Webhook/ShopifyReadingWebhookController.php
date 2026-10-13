<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\Shopify\Webhooks\WebhookInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Shopify orders/paid for Email Reading Automation. Stores the event
 * and queues its processing; the work happens in ReadingOrderPaidHandler.
 */
class ShopifyReadingWebhookController extends Controller
{
    public function __construct(private readonly WebhookInbox $inbox) {}

    public function handle(Request $request): JsonResponse
    {
        return $this->inbox->receive($request, 'reading.paid', 'orders/paid');
    }
}
