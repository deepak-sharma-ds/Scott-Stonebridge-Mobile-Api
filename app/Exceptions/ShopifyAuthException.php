<?php

namespace App\Exceptions;

/**
 * Exception thrown for Shopify authentication failures.
 * 
 * This exception is used when authentication with Shopify fails,
 * such as invalid access tokens or expired credentials.
 */
class ShopifyAuthException extends ShopifyException
{
    protected int $httpStatusCode = 401;
    protected string $errorCode = 'AUTH_FAILED';

    /**
     * Create a new Shopify authentication exception instance.
     *
     * @param string $message The exception message
     * @param int $code The exception code
     * @param \Throwable|null $previous The previous throwable used for exception chaining
     * @param array $context Additional context data
     */
    public function __construct(
        string $message = 'Authentication failed',
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
    }
}
