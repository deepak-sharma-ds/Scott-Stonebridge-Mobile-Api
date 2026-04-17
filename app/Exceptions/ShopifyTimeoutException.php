<?php

namespace App\Exceptions;

/**
 * Exception thrown when a Shopify API request times out.
 * 
 * This exception is used when requests to Shopify take too long
 * and exceed the configured timeout threshold.
 */
class ShopifyTimeoutException extends ShopifyException
{
    protected int $httpStatusCode = 504;
    protected string $errorCode = 'TIMEOUT';

    /**
     * Create a new Shopify timeout exception instance.
     *
     * @param string $message The exception message
     * @param int $code The exception code
     * @param \Throwable|null $previous The previous throwable used for exception chaining
     * @param array $context Additional context data
     */
    public function __construct(
        string $message = 'Request timeout',
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
    }
}
