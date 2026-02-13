# Shopify Exception Hierarchy

This directory contains the exception hierarchy for Shopify-related errors in the Laravel application.

## Exception Hierarchy

```
Exception
└── ShopifyException (base for all Shopify-related errors)
    ├── ShopifyApiException (general API errors)
    ├── ShopifyAuthException (authentication failures)
    ├── ShopifyRateLimitException (rate limit exceeded)
    ├── ShopifyNotFoundException (resource not found)
    ├── ShopifyValidationException (validation errors from Shopify)
    └── ShopifyTimeoutException (request timeouts)
```

## Exception-to-HTTP-Status Mapping

| Exception Type | HTTP Status | Error Code | Default Message |
|---------------|-------------|------------|-----------------|
| ShopifyException | 500 | SHOPIFY_ERROR | (custom message) |
| ShopifyApiException | 500 | API_ERROR | External API error |
| ShopifyAuthException | 401 | AUTH_FAILED | Authentication failed |
| ShopifyNotFoundException | 404 | NOT_FOUND | Resource not found |
| ShopifyValidationException | 422 | VALIDATION_ERROR | Validation failed |
| ShopifyRateLimitException | 429 | RATE_LIMIT | Rate limit exceeded |
| ShopifyTimeoutException | 504 | TIMEOUT | Request timeout |

## Usage Examples

### Basic Exception

```php
use App\Exceptions\ShopifyNotFoundException;

throw new ShopifyNotFoundException('Product not found');
```

### Exception with Context

```php
use App\Exceptions\ShopifyApiException;

throw new ShopifyApiException(
    'Failed to fetch product',
    0,
    null,
    ['handle' => 'example-product', 'operation' => 'getProduct']
);
```

### Validation Exception with Errors

```php
use App\Exceptions\ShopifyValidationException;

throw new ShopifyValidationException(
    'Invalid cart data',
    [
        'variant_id' => ['The variant ID is invalid.'],
        'quantity' => ['The quantity must be at least 1.']
    ]
);
```

### Rate Limit Exception with Retry-After

```php
use App\Exceptions\ShopifyRateLimitException;

throw new ShopifyRateLimitException(
    'Rate limit exceeded',
    60  // retry after 60 seconds
);
```

### Exception Chaining

```php
use App\Exceptions\ShopifyTimeoutException;

try {
    // Some operation that times out
} catch (\Exception $e) {
    throw new ShopifyTimeoutException(
        'Request timed out',
        0,
        $e  // chain the original exception
    );
}
```

## Converting to API Response

All exceptions can be converted to a standardized API response format:

```php
$exception = new ShopifyNotFoundException('Product not found');
$response = $exception->toArray();

// Returns:
// [
//     'success' => false,
//     'message' => 'Product not found',
//     'data' => [],
//     'meta' => [
//         'error_code' => 'NOT_FOUND',
//         'timestamp' => '2025-01-20T10:30:00Z'
//     ]
// ]
```

## Exception Properties

### Common Properties

All exceptions inherit these properties from `ShopifyException`:

- `httpStatusCode`: HTTP status code to return (default: 500)
- `errorCode`: Error code for API responses (default: 'SHOPIFY_ERROR')
- `context`: Additional context data for logging and debugging

### Common Methods

- `getHttpStatusCode()`: Get the HTTP status code
- `getErrorCode()`: Get the error code
- `getContext()`: Get the context data
- `setContext(array $context)`: Add additional context data
- `toArray()`: Convert to API response format

### Special Exception Features

**ShopifyValidationException**
- `getErrors()`: Get validation errors array
- Includes errors in `data.errors` when converting to array

**ShopifyRateLimitException**
- `getRetryAfter()`: Get retry-after value in seconds
- Includes `retry_after` in `meta` when converting to array

## Integration with Laravel Exception Handler

To integrate these exceptions with Laravel's exception handler, add to `app/Exceptions/Handler.php`:

```php
use App\Exceptions\ShopifyException;

public function render($request, Throwable $exception)
{
    if ($exception instanceof ShopifyException) {
        return response()->json(
            $exception->toArray(),
            $exception->getHttpStatusCode()
        );
    }

    return parent::render($request, $exception);
}
```

## Testing

All exceptions have comprehensive unit tests in `tests/Unit/Exceptions/`. Run tests with:

```bash
php artisan test tests/Unit/Exceptions/
```

## Requirements Validation

This exception hierarchy validates the following requirements:

- **5.10**: Implement a base exception handler for API errors
- **13.1**: Transform Shopify API errors to standard format
- **13.2**: Return 422 status with validation errors
- **13.3**: Return 401 status with error message for authentication failures
- **13.4**: Return 404 status with error message for not found resources
- **13.5**: Return 429 status with retry information for rate limits
- **13.6**: Return 500 status with safe error message for server errors
