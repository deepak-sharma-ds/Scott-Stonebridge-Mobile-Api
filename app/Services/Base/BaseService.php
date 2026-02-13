<?php

namespace App\Services\Base;

use App\Logging\CorrelationIdProcessor;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Base Service Class
 * 
 * Provides common service patterns including:
 * - Correlation ID tracking
 * - Structured logging
 * - Performance logging helpers
 * - Error handling utilities
 * 
 * All service classes should extend this base class to ensure
 * consistent logging, error handling, and observability patterns.
 * 
 * Requirements: 5.8
 */
abstract class BaseService
{
    /**
     * The correlation ID for the current request
     *
     * @var string|null
     */
    protected ?string $correlationId = null;

    /**
     * The service name for logging context
     *
     * @var string
     */
    protected string $serviceName;

    /**
     * Constructor
     */
    public function __construct()
    {
        $this->serviceName = class_basename(static::class);
        $this->correlationId = $this->resolveCorrelationId();
    }

    /**
     * Resolve correlation ID from request or processor
     *
     * @return string
     */
    protected function resolveCorrelationId(): string
    {
        // Try to get from CorrelationIdProcessor first
        $correlationId = CorrelationIdProcessor::getCurrentCorrelationId();

        if ($correlationId !== null) {
            return $correlationId;
        }

        // Try to get from request
        try {
            if (app()->bound('request')) {
                $request = app('request');
                if ($request && $request->hasHeader('X-Correlation-ID')) {
                    return $request->header('X-Correlation-ID');
                }
                if ($request && $request->attributes->has('correlation_id')) {
                    return $request->attributes->get('correlation_id');
                }
            }
        } catch (\Throwable $e) {
            // Continue to generate new ID
        }

        // Generate new UUID if not available
        return (string) Str::uuid();
    }

    /**
     * Get the correlation ID
     *
     * @return string
     */
    protected function getCorrelationId(): string
    {
        return $this->correlationId;
    }

    /**
     * Log an informational message with service context
     *
     * @param string $message
     * @param array $context
     * @param string|null $channel
     * @return void
     */
    protected function logInfo(string $message, array $context = [], ?string $channel = null): void
    {
        $this->log('info', $message, $context, $channel);
    }

    /**
     * Log a warning message with service context
     *
     * @param string $message
     * @param array $context
     * @param string|null $channel
     * @return void
     */
    protected function logWarning(string $message, array $context = [], ?string $channel = null): void
    {
        $this->log('warning', $message, $context, $channel);
    }

    /**
     * Log an error message with service context
     *
     * @param string $message
     * @param array $context
     * @param string|null $channel
     * @return void
     */
    protected function logError(string $message, array $context = [], ?string $channel = null): void
    {
        $this->log('error', $message, $context, $channel);
    }

    /**
     * Log a debug message with service context
     *
     * @param string $message
     * @param array $context
     * @param string|null $channel
     * @return void
     */
    protected function logDebug(string $message, array $context = [], ?string $channel = null): void
    {
        $this->log('debug', $message, $context, $channel);
    }

    /**
     * Log a message with service context
     *
     * Automatically adds correlation ID, service name, and timestamp
     * to all log entries for consistent structured logging.
     *
     * @param string $level
     * @param string $message
     * @param array $context
     * @param string|null $channel
     * @return void
     */
    protected function log(string $level, string $message, array $context = [], ?string $channel = null): void
    {
        $enrichedContext = array_merge([
            'service' => $this->serviceName,
            'correlation_id' => $this->getCorrelationId(),
            'timestamp' => now()->toIso8601String(),
        ], $context);

        $logger = $channel ? Log::channel($channel) : Log::channel('api');

        $logger->log($level, $message, $enrichedContext);
    }

    /**
     * Log performance metrics for an operation
     *
     * @param string $operation
     * @param float $duration Duration in milliseconds
     * @param array $additionalMetrics
     * @return void
     */
    protected function logPerformance(string $operation, float $duration, array $additionalMetrics = []): void
    {
        $context = array_merge([
            'operation' => $operation,
            'duration_ms' => round($duration, 2),
        ], $additionalMetrics);

        $this->log('info', "Performance: {$operation}", $context, 'performance');
    }

    /**
     * Execute a callable and log its performance
     *
     * @param string $operation
     * @param callable $callback
     * @param array $additionalMetrics
     * @return mixed
     */
    protected function withPerformanceLogging(string $operation, callable $callback, array $additionalMetrics = []): mixed
    {
        $startTime = microtime(true);

        try {
            $result = $callback();

            $duration = (microtime(true) - $startTime) * 1000;
            $this->logPerformance($operation, $duration, array_merge($additionalMetrics, ['status' => 'success']));

            return $result;
        } catch (\Throwable $e) {
            $duration = (microtime(true) - $startTime) * 1000;
            $this->logPerformance($operation, $duration, array_merge($additionalMetrics, [
                'status' => 'error',
                'error' => $e->getMessage(),
            ]));

            throw $e;
        }
    }

    /**
     * Log an exception with full context
     *
     * @param \Throwable $exception
     * @param string $operation
     * @param array $context
     * @return void
     */
    protected function logException(\Throwable $exception, string $operation, array $context = []): void
    {
        $enrichedContext = array_merge([
            'operation' => $operation,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ], $context);

        $this->log('error', "Exception in {$operation}: {$exception->getMessage()}", $enrichedContext, 'error');
    }

    /**
     * Build context array for logging
     *
     * @param array $context
     * @return array
     */
    protected function buildLogContext(array $context = []): array
    {
        return array_merge([
            'service' => $this->serviceName,
            'correlation_id' => $this->getCorrelationId(),
            'timestamp' => now()->toIso8601String(),
        ], $context);
    }

    /**
     * Performance tracking storage
     *
     * @var array
     */
    private array $performanceTracking = [];

    /**
     * Start performance tracking for an operation
     *
     * @param string $operation
     * @return void
     */
    protected function logPerformanceStart(string $operation): void
    {
        $this->performanceTracking[$operation] = microtime(true);
    }

    /**
     * End performance tracking and log metrics
     *
     * @param string $operation
     * @param array $additionalMetrics
     * @return void
     */
    protected function logPerformanceEnd(string $operation, array $additionalMetrics = []): void
    {
        if (!isset($this->performanceTracking[$operation])) {
            $this->logWarning("Performance tracking not started for operation: {$operation}");
            return;
        }

        $startTime = $this->performanceTracking[$operation];
        $duration = (microtime(true) - $startTime) * 1000;

        unset($this->performanceTracking[$operation]);

        $this->logPerformance($operation, $duration, $additionalMetrics);
    }

    /**
     * Log an error with exception details
     *
     * @param string $message
     * @param \Throwable $exception
     * @param array $context
     * @return void
     */
    protected function logErrorWithException(string $message, \Throwable $exception, array $context = []): void
    {
        $enrichedContext = array_merge([
            'exception' => get_class($exception),
            'exception_message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
        ], $context);

        $this->log('error', $message, $enrichedContext, 'error');
    }
}

