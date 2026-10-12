<?php

namespace App\Http\Controllers\Webhook\Campaign;

use App\Http\Controllers\Controller;
use App\Services\Shopify\Webhooks\WebhookInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Shopify orders/paid for Campaign Email Automation. Fully isolated
 * from the reading flow (separate route, subscription, handler, tables):
 * stores the event and queues its processing; the work happens in
 * CampaignOrderPaidHandler.
 */
class CampaignPaidWebhookController extends Controller
{
    public function __construct(private readonly WebhookInbox $inbox) {}

    public function handle(Request $request): JsonResponse
    {
        return $this->inbox->receive($request, 'campaign.paid', 'orders/paid');
    }
}
