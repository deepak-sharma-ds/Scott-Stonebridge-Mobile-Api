<?php

namespace App\Exceptions;

/**
 * Exception thrown when a requested Shopify resource is not found.
 * 
 * This exception is used when products, carts, orders, or other
 * Shopify resources cannot be found.
 */
class ShopifyNotFoundException extends ShopifyException
{
    protected int $httpStatusCode = 404;
    protected string $errorCode = 'NOT_FOUND';

    /**
     * Create a new Shopify not found exception instance.
     *
     * @param string $message The exception message
     * @param int $code The exception code
     * @param \Throwable|null $previous The previous throwable used for exception chaining
     * @param array $context Additional context data
     */
    public function __construct(
        string $message = 'Resource not found',
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
    }
}
