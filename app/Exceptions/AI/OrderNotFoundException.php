<?php

declare(strict_types=1);

namespace App\Exceptions\AI;

use Throwable;

/**
 * Thrown by OrderTrackingService when Shopify Admin returns no match for the
 * (order_number, email) pair OR when the returned order's email doesn't equal
 * the submitted email after normalisation. Extends AIException so the existing
 * controller catch path renders the standard 404 envelope.
 */
class OrderNotFoundException extends AIException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = 'Order not found.',
        array $context = [],
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            message: $message,
            httpStatus: 404,
            errorCode: 'order_not_found',
            errorContext: $context,
            previous: $previous,
        );
    }
}
