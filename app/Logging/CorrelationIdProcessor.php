<?php

namespace App\Logging;

use Illuminate\Support\Str;
use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;

/**
 * Monolog processor that adds correlation ID to all log records
 *
 * This processor adds a correlation_id field to every log entry,
 * enabling request tracking across system layers and external services.
 *
 * The correlation ID is:
 * - Generated once per request
 * - Stored in the request context
 * - Included in all log entries
 * - Passed to external API calls
 * - Returned in response headers
 */
class CorrelationIdProcessor implements ProcessorInterface
{
    /**
     * The correlation ID for the current request
     */
    protected static ?string $correlationId = null;

    /**
     * Add correlation ID to the log record
     */
    public function __invoke(LogRecord $record): LogRecord
    {
        $correlationId = $this->getCorrelationId();

        // Add correlation_id to the extra context
        $record->extra['correlation_id'] = $correlationId;

        return $record;
    }

    /**
     * Get or generate correlation ID
     */
    protected function getCorrelationId(): string
    {
        if (self::$correlationId === null) {
            // Try to get from request context first
            try {
                if (app()->bound('request')) {
                    $request = app('request');
                    if ($request && $request->hasHeader('X-Correlation-ID')) {
                        self::$correlationId = $request->header('X-Correlation-ID');
                    }
                }
            } catch (\Throwable $e) {
                // If request is not available or any error occurs, continue to generate new ID
            }

            // Generate new UUID v4 if not set from request
            if (self::$correlationId === null) {
                self::$correlationId = (string) Str::uuid();
            }
        }

        return self::$correlationId;
    }

    /**
     * Set correlation ID (used by middleware)
     */
    public static function setCorrelationId(string $correlationId): void
    {
        self::$correlationId = $correlationId;
    }

    /**
     * Get current correlation ID
     */
    public static function getCurrentCorrelationId(): ?string
    {
        return self::$correlationId;
    }

    /**
     * Reset correlation ID (useful for testing)
     */
    public static function reset(): void
    {
        self::$correlationId = null;
    }
}
