<?php

namespace App\Exceptions;

/**
 * Exception thrown when Shopify API rate limits are exceeded.
 * 
 * This exception includes retry-after information to help clients
 * understand when they can retry the request.
 */
class ShopifyRateLimitException extends ShopifyException
{
    protected int $httpStatusCode = 429;
    protected string $errorCode = 'RATE_LIMIT';

    /**
     * Number of seconds to wait before retrying.
     */
    protected ?int $retryAfter = null;

    /**
     * Create a new Shopify rate limit exception instance.
     *
     * @param string $message The exception message
     * @param int|null $retryAfter Number of seconds to wait before retrying
     * @param int $code The exception code
     * @param \Throwable|null $previous The previous throwable used for exception chaining
     * @param array $context Additional context data
     */
    public function __construct(
        string $message = 'Rate limit exceeded',
        ?int $retryAfter = null,
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
        $this->retryAfter = $retryAfter;
    }

    /**
     * Get the retry-after value in seconds.
     */
    public function getRetryAfter(): ?int
    {
        return $this->retryAfter;
    }

    /**
     * Convert the exception to an array for API responses.
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        
        if ($this->retryAfter !== null) {
            $array['meta']['retry_after'] = $this->retryAfter;
        }
        
        return $array;
    }
}
