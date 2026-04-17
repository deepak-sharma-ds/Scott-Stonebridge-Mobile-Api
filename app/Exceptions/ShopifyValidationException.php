<?php

namespace App\Exceptions;

/**
 * Exception thrown for Shopify validation errors.
 * 
 * This exception is used when Shopify rejects a request due to
 * validation failures, such as invalid input data or business rule violations.
 */
class ShopifyValidationException extends ShopifyException
{
    protected int $httpStatusCode = 422;
    protected string $errorCode = 'VALIDATION_ERROR';

    /**
     * Validation errors from Shopify.
     */
    protected array $errors = [];

    /**
     * Create a new Shopify validation exception instance.
     *
     * @param string $message The exception message
     * @param array $errors Validation errors from Shopify
     * @param int $code The exception code
     * @param \Throwable|null $previous The previous throwable used for exception chaining
     * @param array $context Additional context data
     */
    public function __construct(
        string $message = 'Validation failed',
        array $errors = [],
        int $code = 0,
        ?\Throwable $previous = null,
        array $context = []
    ) {
        parent::__construct($message, $code, $previous, $context);
        $this->errors = $errors;
    }

    /**
     * Get the validation errors.
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    /**
     * Convert the exception to an array for API responses.
     */
    public function toArray(): array
    {
        $array = parent::toArray();
        
        if (!empty($this->errors)) {
            $array['data']['errors'] = $this->errors;
        }
        
        return $array;
    }
}
