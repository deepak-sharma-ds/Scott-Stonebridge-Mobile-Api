<?php

declare(strict_types=1);

namespace App\Contracts\Services\AI;

use App\DTOs\AI\OrderTrackingDTO;
use App\Exceptions\AI\OrderNotFoundException;

/**
 * Resolves order status for the chat widget's "Where's my order?" flow.
 * Implementations MUST validate the (orderNumber, email) pair against the
 * Shopify Admin API and throw OrderNotFoundException on any mismatch — never
 * leak details about whether the order exists vs. the email being wrong.
 */
interface OrderTrackingServiceInterface
{
    /**
     * @throws OrderNotFoundException when no match exists for the
     *                                (orderNumber, email) pair.
     */
    public function track(string $shopDomain, string $orderNumber, string $email): OrderTrackingDTO;
}
