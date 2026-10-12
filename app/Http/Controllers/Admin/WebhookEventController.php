<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ShopifyWebhookEvent;
use App\Services\Shopify\Webhooks\WebhookInbox;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin view over the webhook inbox: see every stored Shopify event with its
 * outcome, and replay a failed (or stuck) one.
 */
class WebhookEventController extends Controller
{
    public function __construct(private readonly WebhookInbox $inbox) {}

    public function index(Request $request): View
    {
        $filters = $request->only(['status', 'endpoint', 'search']);

        $events = ShopifyWebhookEvent::query()
            ->status($filters['status'] ?? null)
            ->when(filled($filters['endpoint'] ?? null), fn ($q) => $q->where('endpoint', $filters['endpoint']))
            ->when(filled($filters['search'] ?? null), fn ($q) => $q->where('shopify_order_id', 'like', '%'.trim((string) $filters['search']).'%'))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.webhook_events.index', [
            'events' => $events,
            'statuses' => ShopifyWebhookEvent::statusLabels(),
            'endpoints' => array_keys((array) config('shopify.webhook_inbox.handlers', [])),
            'filters' => $filters,
        ]);
    }

    public function replay(ShopifyWebhookEvent $event): RedirectResponse
    {
        if (in_array($event->status, [ShopifyWebhookEvent::STATUS_PROCESSED, ShopifyWebhookEvent::STATUS_IGNORED], true)) {
            return back()->with('error', 'Only failed or stuck events can be replayed.');
        }

        ShopifyWebhookEvent::query()->whereKey($event->id)->update([
            'status' => ShopifyWebhookEvent::STATUS_RECEIVED,
            'error' => null,
        ]);

        $this->inbox->dispatch($event);

        return back()->with('success', 'Event #'.$event->id.' re-queued for processing.');
    }
}
