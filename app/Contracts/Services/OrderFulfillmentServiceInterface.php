<?php

namespace App\Contracts\Services;

use App\Exceptions\ShopifyApiException;

interface OrderFulfillmentServiceInterface
{
    /**
     * Fulfill specific order line items via the Shopify Admin API.
     *
     * Only the given line items are fulfilled, so an order that also contains
     * physical (or other) products is left PARTIALLY_FULFILLED by Shopify —
     * exactly the behaviour wanted when only the email-reading item ships.
     *
     * Idempotent at the Shopify level: line items with no remaining quantity
     * (already fulfilled) are skipped; when nothing remains to fulfill the
     * method returns ['fulfilled' => false] without calling the mutation.
     *
     * @param  int  $shopifyOrderId  Numeric Shopify order id
     * @param  array<int,int>  $lineItemIds  Numeric Shopify order line-item ids to fulfill
     * @param  bool  $notifyCustomer  Whether Shopify sends its own shipping notification
     * @return array{fulfilled:bool,fulfillment_id:?string,status:?string}
     *
     * @throws ShopifyApiException
     */
    public function fulfillLineItems(int $shopifyOrderId, array $lineItemIds, bool $notifyCustomer = false): array;
}
