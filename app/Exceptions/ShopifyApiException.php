<?php

namespace App\Exceptions;

/**
 * Exception thrown for general Shopify API errors.
 * 
 * This exception is used for server errors, network issues, and other
 * general API failures that don't fit into more specific categories.
 */
class ShopifyApiException extends ShopifyException
{
    protected int $httpStatusCode = 500;
    protected string $errorCode = 'API_ERROR';

    /**
     * Create a new Shopify API exception instance.
     *
     * @param string $message The exception message
     * @param int $code The exception code
     * @param \Throwable|null $previous The previous throwable used for exception chaining
     * @param array $context Additional context data
     */
    public function __construct(
        string $message = 'External API error',
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
    }
}
