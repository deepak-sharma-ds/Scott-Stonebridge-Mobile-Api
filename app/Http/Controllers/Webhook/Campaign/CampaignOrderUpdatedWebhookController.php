<?php

namespace App\Http\Controllers\Webhook\Campaign;

use App\Http\Controllers\Controller;
use App\Services\Shopify\Webhooks\WebhookInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Receives Shopify orders/updated for Campaign Email Automation (same-day
 * upgrade). Stores the event and queues its processing; the work happens in
 * CampaignOrderUpdatedHandler.
 */
class CampaignOrderUpdatedWebhookController extends Controller
{
    public function __construct(private readonly WebhookInbox $inbox) {}

    public function handle(Request $request): JsonResponse
    {
        return $this->inbox->receive($request, 'campaign.updated', 'orders/updated');
    }
}
