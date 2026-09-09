<?php

namespace App\Http\Controllers\Api\V1\Push;

use App\Http\Controllers\Api\V1\Push\Concerns\ResolvesAuthenticatedCustomer;
use App\Http\Controllers\Base\BaseApiController;
use App\Http\Requests\Push\RegisterDeviceTokenRequest;
use App\Http\Requests\Push\UnregisterDeviceTokenRequest;
use App\Http\Requests\Push\UpdatePushPreferencesRequest;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;

/**
 * Mobile-facing device token registration for marketing push notifications.
 * Identity comes from the shopify.auth middleware (shopify_customer_data);
 * tokens are keyed by email so Klaviyo recipients (also keyed by email) map
 * cleanly to devices.
 */
class DeviceTokenController extends BaseApiController
{
    use ResolvesAuthenticatedCustomer;

    /**
     * Register or refresh the caller's device token (upsert on fcm_token).
     */
    public function store(RegisterDeviceTokenRequest $request): JsonResponse
    {
        $customer = $this->customer($request);

        if (! $customer['email']) {
            return $this->error('Authenticated customer has no email', [], [], 422);
        }

        DeviceToken::updateOrCreate(
            ['fcm_token' => $request->validated('fcm_token')],
            [
                'shopify_customer_id' => $customer['id'],
                'customer_email' => $customer['email'],
                'platform' => $request->validated('platform'),
                'device_id' => $request->validated('device_id'),
                'app_version' => $request->validated('app_version'),
                'push_enabled' => true,
                'last_seen_at' => now(),
                'revoked_at' => null,
            ]
        );

        return $this->success('Device token registered', [], [], 201);
    }

    /**
     * Unregister a device token (called on logout).
     */
    public function destroy(UnregisterDeviceTokenRequest $request): JsonResponse
    {
        DeviceToken::where('fcm_token', $request->validated('fcm_token'))->delete();

        return $this->success('Device token unregistered');
    }

    /**
     * Toggle push for all of the caller's devices (opt in/out).
     */
    public function updatePreferences(UpdatePushPreferencesRequest $request): JsonResponse
    {
        $customer = $this->customer($request);

        DeviceToken::where('shopify_customer_id', $customer['id'])
            ->update(['push_enabled' => (bool) $request->validated('enabled')]);

        return $this->success('Push preferences updated');
    }
}
