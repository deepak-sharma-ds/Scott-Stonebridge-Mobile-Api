<?php

namespace App\Exceptions;

use Exception;

/**
 * Base exception class for all Shopify-related errors.
 * 
 * This exception serves as the parent for all Shopify API and integration errors,
 * providing a common interface for exception handling and HTTP status mapping.
 */
class ShopifyException extends Exception
{
    /**
     * HTTP status code to return for this exception.
     */
    protected int $httpStatusCode = 500;

    /**
     * Error code for API responses.
     */
    protected string $errorCode = 'SHOPIFY_ERROR';

    /**
     * Additional context data for logging and debugging.
     */
    protected array $context = [];

    /**
     * Create a new Shopify exception instance.
     *
     * @param string $message The exception message
     * @param int $code The exception code
     * @param \Throwable|null $previous The previous throwable used for exception chaining
     * @param array $context Additional context data
     */
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->context = $context;
    }

    /**
     * Get the HTTP status code for this exception.
     */
    public function getHttpStatusCode(): int
    {
        return $this->httpStatusCode;
    }

    /**
     * Get the error code for API responses.
     */
    public function getErrorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Get the context data for this exception.
     */
    public function getContext(): array
    {
        return $this->context;
    }

    /**
     * Set additional context data.
     */
    public function setContext(array $context): self
    {
        $this->context = array_merge($this->context, $context);
        return $this;
    }

    /**
     * Convert the exception to an array for API responses.
     */
    public function toArray(): array
    {
        return [
            'success' => false,
            'message' => $this->getMessage(),
            'data' => [],
            'meta' => [
                'error_code' => $this->getErrorCode(),
                'timestamp' => now()->toIso8601String(),
            ],
        ];
    }
}
