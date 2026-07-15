<?php

namespace App\Http\Controllers\Api\V1\Push;

use App\Http\Controllers\Api\V1\Push\Concerns\ResolvesAuthenticatedCustomer;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Resources\Push\NotificationResource;
use App\Models\PushNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app notification center for the mobile app: lists the push
 * notifications a customer has actually received, and lets the app open one
 * for detail (which marks it read). Backed by the existing push_notifications
 * delivery log — no separate storage.
 */
class NotificationController extends BaseApiController
{
    use ResolvesAuthenticatedCustomer;

    /**
     * List the caller's delivered notifications, newest first.
     * Optional ?status=unread|read filters; default is all.
     */
    public function index(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        if (! $customer['email']) {
            return $this->error('Authenticated customer has no email', [], [], 422);
        }

        $perPage = max(1, min(100, (int) $request->input('per_page', 20)));

        $base = fn () => PushNotification::query()
            ->where('recipient_email', $customer['email'])
            ->where('status', PushNotification::STATUS_SENT)
            ->visible();

        $query = $base()->orderByDesc('sent_at');

        if ($request->input('status') === 'unread') {
            $query->unread();
        } elseif ($request->input('status') === 'read') {
            $query->read();
        }

        $paginator = $query->paginate($perPage);

        return $this->successWithPagination(
            'Notifications fetched',
            NotificationResource::collection($paginator->items()),
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'has_more' => $paginator->hasMorePages(),
                'unread_count' => $base()->unread()->count(),
            ],
        );
    }

    /**
     * Show a single notification and mark it read.
     */
    public function show(Request $request, PushNotification $pushNotification): JsonResponse
    {
        $customer = $this->customer($request);

        if (! $this->owns($pushNotification, $customer['email'])) {
            return $this->notFound('Notification not found');
        }

        $pushNotification->markRead();

        return $this->success('Notification fetched', new NotificationResource($pushNotification));
    }

    /**
     * Clear a single notification from the caller's in-app list. The
     * delivery record is kept (cleared_at is set, nothing is deleted); it
     * just stops appearing in index()/show().
     */
    public function destroy(Request $request, PushNotification $pushNotification): JsonResponse
    {
        $customer = $this->customer($request);

        if (! $this->owns($pushNotification, $customer['email'])) {
            return $this->notFound('Notification not found');
        }

        $pushNotification->clear();

        return $this->success('Notification cleared');
    }

    /**
     * Clear every notification currently visible to the caller (the "Clear
     * all" button). Same soft-hide semantics as destroy() — no data loss.
     */
    public function clearAll(Request $request): JsonResponse
    {
        $customer = $this->customer($request);

        if (! $customer['email']) {
            return $this->error('Authenticated customer has no email', [], [], 422);
        }

        PushNotification::query()
            ->where('recipient_email', $customer['email'])
            ->where('status', PushNotification::STATUS_SENT)
            ->visible()
            ->update(['cleared_at' => now()]);

        return $this->success('Notifications cleared');
    }

    private function owns(PushNotification $pushNotification, ?string $email): bool
    {
        return $pushNotification->recipient_email === $email
            && $pushNotification->status === PushNotification::STATUS_SENT
            && $pushNotification->cleared_at === null;
    }
}
