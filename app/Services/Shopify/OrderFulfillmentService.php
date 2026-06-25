<?php

namespace App\Services\Shopify;

use App\Contracts\Services\OrderFulfillmentServiceInterface;
use App\Contracts\Shopify\AdminApiClientInterface;
use App\Exceptions\ShopifyApiException;
use App\Services\Base\BaseService;

/**
 * Order Fulfillment Service
 *
 * Marks specific order line items fulfilled via the Shopify Admin GraphQL API
 * (AdminApiClient + app/GraphQL/admin/fulfillment/*). Fulfilling only the
 * reading line item leaves any physical items unfulfilled, so Shopify reports
 * the order as PARTIALLY_FULFILLED automatically.
 */
class OrderFulfillmentService extends BaseService implements OrderFulfillmentServiceInterface
{
    public function __construct(
        private readonly AdminApiClientInterface $adminClient
    ) {
        parent::__construct();
    }

    /**
     * Fulfill the given order line items (partial fulfillment).
     *
     * @param  array<int,int>  $lineItemIds
     * @return array{fulfilled:bool,fulfillment_id:?string,status:?string}
     *
     * @throws ShopifyApiException
     */
    public function fulfillLineItems(int $shopifyOrderId, array $lineItemIds, bool $notifyCustomer = false): array
    {
        $this->logPerformanceStart('fulfillLineItems');

        try {
            // Target the specific order line items (as GIDs) we want fulfilled.
            $targets = [];
            foreach ($lineItemIds as $id) {
                $id = (int) $id;
                if ($id > 0) {
                    $targets["gid://shopify/LineItem/{$id}"] = true;
                }
            }

            if (empty($targets)) {
                return ['fulfilled' => false, 'fulfillment_id' => null, 'status' => null];
            }

            $response = $this->adminClient->query('admin/fulfillment/get_fulfillment_orders', [
                'id' => "gid://shopify/Order/{$shopifyOrderId}",
            ]);

            $payload = $this->buildLineItemsPayload($response, $targets);

            // Nothing open to fulfill for these line items (already fulfilled,
            // cancelled, or not present): idempotent no-op.
            if (empty($payload)) {
                $this->logPerformanceEnd('fulfillLineItems', [
                    'order_id' => $shopifyOrderId,
                    'fulfilled' => false,
                ]);

                return ['fulfilled' => false, 'fulfillment_id' => null, 'status' => null];
            }

            $result = $this->adminClient->query('admin/fulfillment/create_fulfillment', [
                'fulfillment' => [
                    'lineItemsByFulfillmentOrder' => $payload,
                    'notifyCustomer' => $notifyCustomer,
                ],
            ]);

            $userErrors = $result['data']['fulfillmentCreate']['userErrors'] ?? [];
            if (! empty($userErrors)) {
                $message = 'Fulfillment failed: '.json_encode($userErrors);
                $this->logError($message, ['order_id' => $shopifyOrderId, 'errors' => $userErrors]);
                throw new ShopifyApiException($message);
            }

            $fulfillment = $result['data']['fulfillmentCreate']['fulfillment'] ?? [];

            $this->logPerformanceEnd('fulfillLineItems', [
                'order_id' => $shopifyOrderId,
                'fulfilled' => true,
                'fulfillment_id' => $fulfillment['id'] ?? null,
                'status' => $fulfillment['status'] ?? null,
            ]);

            return [
                'fulfilled' => true,
                'fulfillment_id' => $fulfillment['id'] ?? null,
                'status' => $fulfillment['status'] ?? null,
            ];
        } catch (\Exception $e) {
            $this->logErrorWithException('Failed to fulfill line items', $e, [
                'order_id' => $shopifyOrderId,
                'line_item_ids' => $lineItemIds,
            ]);
            throw $e;
        }
    }

    /**
     * From the fulfillment-orders query response, collect the open fulfillment
     * order line items that belong to the target order line items.
     *
     * @param  array<string,bool>  $targets  Set of target line-item GIDs
     * @return array<int,array{fulfillmentOrderId:string,fulfillmentOrderLineItems:array<int,array{id:string,quantity:int}>}>
     */
    private function buildLineItemsPayload(array $response, array $targets): array
    {
        $fulfillmentOrders = $response['data']['order']['fulfillmentOrders']['edges'] ?? [];

        $payload = [];

        foreach ($fulfillmentOrders as $foEdge) {
            $fo = $foEdge['node'] ?? [];

            if (($fo['status'] ?? null) !== 'OPEN') {
                continue;
            }

            $items = [];
            foreach (($fo['lineItems']['edges'] ?? []) as $liEdge) {
                $li = $liEdge['node'] ?? [];
                $orderLineItemGid = $li['lineItem']['id'] ?? null;
                $remaining = (int) ($li['remainingQuantity'] ?? 0);

                if (empty($li['id']) || $remaining <= 0) {
                    continue;
                }

                if ($orderLineItemGid === null || ! isset($targets[$orderLineItemGid])) {
                    continue;
                }

                $items[] = [
                    'id' => $li['id'],
                    'quantity' => $remaining,
                ];
            }

            if (! empty($items)) {
                $payload[] = [
                    'fulfillmentOrderId' => $fo['id'],
                    'fulfillmentOrderLineItems' => $items,
                ];
            }
        }

        return $payload;
    }
}
