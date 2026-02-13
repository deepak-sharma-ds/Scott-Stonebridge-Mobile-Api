<?php

namespace App\Clients\Concerns;

use App\Exceptions\ShopifyApiException;
use App\Exceptions\ShopifyTimeoutException;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Log;

trait HasRetryLogic
{
    /**
     * Maximum number of retry attempts
     */
    protected ?int $retryMaxAttempts = null;

    /**
     * Initial retry delay in milliseconds
     */
    protected ?int $retryDelayMs = null;

    /**
     * Execute a callable with retry logic
     *
     * @param callable $callback The function to execute
     * @param int|null $maxAttempts Maximum retry attempts (null uses config default)
     * @param int|null $initialDelayMs Initial delay in milliseconds (null uses config default)
     * @param string $operationName Name of the operation for logging
     * @return mixed
     * @throws ShopifyTimeoutException
     */
    protected function executeWithRetry(
        callable $callback,
        ?int $maxAttempts = null,
        ?int $initialDelayMs = null,
        string $operationName = 'operation'
    ) {
        $maxAttempts = $maxAttempts ?? $this->retryMaxAttempts ?? config('shopify.retry.max_attempts', 1);
        $delayMs = $initialDelayMs ?? $this->retryDelayMs ?? config('shopify.retry.initial_delay_ms', 100);
        
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxAttempts) {
            $attempt++;

            try {
                return $callback($attempt);
            } catch (\Exception $e) {
                $lastException = $e;

                // Check if error is retryable
                if (!$this->isRetryableError($e)) {
                    throw $e;
                }

                // If we have more attempts, retry
                if ($attempt < $maxAttempts) {
                    $this->logRetryAttempt($operationName, $attempt, $maxAttempts, $delayMs, $e);
                    $this->sleepWithJitter($delayMs);
                    $delayMs = $this->calculateNextDelay($delayMs);
                }
            }
        }

        // All retries exhausted
        throw new ShopifyTimeoutException(
            "Failed after {$maxAttempts} attempts: " . ($lastException ? $lastException->getMessage() : 'Unknown error'),
            0,
            $lastException
        );
    }

    /**
     * Determine if an error is retryable
     *
     * @param \Exception $exception
     * @return bool
     */
    protected function isRetryableError(\Exception $exception): bool
    {
        // Retryable: Connection errors, timeouts, 5xx server errors
        if ($exception instanceof ConnectException) {
            return true;
        }

        if ($exception instanceof ShopifyTimeoutException) {
            return true;
        }

        if ($exception instanceof ShopifyApiException) {
            $statusCode = $exception->getCode();
            // Retry on 5xx errors and 429 (rate limit)
            return $statusCode >= 500 || $statusCode === 429;
        }

        // Non-retryable: 4xx client errors (except 429), validation errors, auth errors
        return false;
    }

    /**
     * Calculate next retry delay with exponential backoff
     *
     * @param int $currentDelayMs Current delay in milliseconds
     * @return int Next delay in milliseconds
     */
    protected function calculateNextDelay(int $currentDelayMs): int
    {
        $multiplier = config('shopify.retry.multiplier', 2.0);
        $maxDelay = config('shopify.retry.max_delay_ms', 5000);

        $nextDelay = (int) ($currentDelayMs * $multiplier);
        $nextDelay = min($nextDelay, $maxDelay);

        return max($nextDelay, 0);
    }

    /**
     * Sleep for specified milliseconds with optional jitter
     *
     * @param int $milliseconds Base delay in milliseconds
     * @return void
     */
    protected function sleepWithJitter(int $milliseconds): void
    {
        $jitterEnabled = config('shopify.retry.jitter', true);

        if ($jitterEnabled) {
            // Add random jitter (±25%)
            $jitter = (int) ($milliseconds * 0.25);
            $milliseconds = $milliseconds + random_int(-$jitter, $jitter);
            $milliseconds = max($milliseconds, 0);
        }

        usleep($milliseconds * 1000);
    }

    /**
     * Log retry attempt
     *
     * @param string $operationName
     * @param int $attempt
     * @param int $maxAttempts
     * @param int $delayMs
     * @param \Exception $exception
     * @return void
     */
    protected function logRetryAttempt(
        string $operationName,
        int $attempt,
        int $maxAttempts,
        int $delayMs,
        \Exception $exception
    ): void {
        $correlationId = request()->header('X-Correlation-ID') ?? 'unknown';

        Log::channel('shopify')->warning('Retrying operation', [
            'correlation_id' => $correlationId,
            'operation' => $operationName,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'delay_ms' => $delayMs,
            'exception' => get_class($exception),
            'message' => $exception->getMessage(),
        ]);
    }

    /**
     * Configure retry behavior
     *
     * @param int $maxAttempts Maximum number of retry attempts
     * @param int $delayMs Initial delay in milliseconds
     * @return self
     */
    public function withRetry(int $maxAttempts, int $delayMs): self
    {
        $this->retryMaxAttempts = $maxAttempts;
        $this->retryDelayMs = $delayMs;
        return $this;
    }

    /**
     * Reset retry configuration
     *
     * @return void
     */
    protected function resetRetryConfig(): void
    {
        $this->retryMaxAttempts = null;
        $this->retryDelayMs = null;
    }
}
