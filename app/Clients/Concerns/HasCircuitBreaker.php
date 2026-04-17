<?php

namespace App\Clients\Concerns;

use App\Exceptions\ShopifyApiException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

trait HasCircuitBreaker
{
    /**
     * Circuit breaker name for the current request
     */
    protected ?string $circuitBreakerName = null;

    /**
     * Circuit breaker states
     */
    protected const STATE_CLOSED = 'closed';
    protected const STATE_OPEN = 'open';
    protected const STATE_HALF_OPEN = 'half_open';

    /**
     * Execute a callable with circuit breaker protection
     *
     * @param callable $callback The function to execute
     * @param string $breakerName Circuit breaker identifier
     * @return mixed
     * @throws ShopifyApiException
     */
    protected function executeWithCircuitBreaker(callable $callback, string $breakerName)
    {
        if (!config('shopify.circuit_breaker.enabled', true)) {
            return $callback();
        }

        $state = $this->getCircuitBreakerState($breakerName);

        // If circuit is open, fail fast
        if ($state === self::STATE_OPEN) {
            $this->logCircuitBreakerOpen($breakerName);
            throw new ShopifyApiException(
                "Circuit breaker '{$breakerName}' is open. Service temporarily unavailable.",
                503
            );
        }

        try {
            $result = $callback();

            // Success - record it
            $this->recordSuccess($breakerName);

            return $result;
        } catch (\Exception $e) {
            // Failure - record it
            $this->recordFailure($breakerName);

            throw $e;
        }
    }

    /**
     * Get the current state of a circuit breaker
     *
     * @param string $breakerName
     * @return string
     */
    protected function getCircuitBreakerState(string $breakerName): string
    {
        $stateKey = $this->getStateKey($breakerName);
        $state = Cache::get($stateKey, self::STATE_CLOSED);

        // Check if we should transition from OPEN to HALF_OPEN
        if ($state === self::STATE_OPEN) {
            $openedAt = Cache::get($this->getOpenedAtKey($breakerName));
            $timeout = config('shopify.circuit_breaker.timeout_seconds', 60);

            if ($openedAt && (time() - $openedAt) >= $timeout) {
                $this->transitionToHalfOpen($breakerName);
                return self::STATE_HALF_OPEN;
            }
        }

        return $state;
    }

    /**
     * Record a successful request
     *
     * @param string $breakerName
     * @return void
     */
    protected function recordSuccess(string $breakerName): void
    {
        $state = $this->getCircuitBreakerState($breakerName);

        if ($state === self::STATE_HALF_OPEN) {
            // Increment success count
            $successKey = $this->getSuccessCountKey($breakerName);
            $successCount = Cache::get($successKey, 0) + 1;
            Cache::put($successKey, $successCount, now()->addMinutes(5));

            $successThreshold = config('shopify.circuit_breaker.success_threshold', 2);

            if ($successCount >= $successThreshold) {
                $this->transitionToClosed($breakerName);
            }
        } elseif ($state === self::STATE_CLOSED) {
            // Reset failure count on success
            Cache::forget($this->getFailureCountKey($breakerName));
        }
    }

    /**
     * Record a failed request
     *
     * @param string $breakerName
     * @return void
     */
    protected function recordFailure(string $breakerName): void
    {
        $state = $this->getCircuitBreakerState($breakerName);

        if ($state === self::STATE_HALF_OPEN) {
            // Failure in half-open state - go back to open
            $this->transitionToOpen($breakerName);
        } elseif ($state === self::STATE_CLOSED) {
            // Increment failure count
            $failureKey = $this->getFailureCountKey($breakerName);
            $windowKey = $this->getWindowStartKey($breakerName);
            $windowSeconds = config('shopify.circuit_breaker.window_seconds', 120);

            // Check if we need to start a new window
            $windowStart = Cache::get($windowKey);
            if (!$windowStart || (time() - $windowStart) >= $windowSeconds) {
                Cache::put($windowKey, time(), now()->addSeconds($windowSeconds * 2));
                Cache::put($failureKey, 1, now()->addSeconds($windowSeconds * 2));
            } else {
                $failureCount = Cache::get($failureKey, 0) + 1;
                Cache::put($failureKey, $failureCount, now()->addSeconds($windowSeconds * 2));

                $failureThreshold = config('shopify.circuit_breaker.failure_threshold', 5);

                if ($failureCount >= $failureThreshold) {
                    $this->transitionToOpen($breakerName);
                }
            }
        }
    }

    /**
     * Transition circuit breaker to CLOSED state
     *
     * @param string $breakerName
     * @return void
     */
    protected function transitionToClosed(string $breakerName): void
    {
        Cache::put($this->getStateKey($breakerName), self::STATE_CLOSED, now()->addHours(24));
        Cache::forget($this->getFailureCountKey($breakerName));
        Cache::forget($this->getSuccessCountKey($breakerName));
        Cache::forget($this->getOpenedAtKey($breakerName));
        Cache::forget($this->getWindowStartKey($breakerName));

        $this->logCircuitBreakerTransition($breakerName, self::STATE_CLOSED);
    }

    /**
     * Transition circuit breaker to OPEN state
     *
     * @param string $breakerName
     * @return void
     */
    protected function transitionToOpen(string $breakerName): void
    {
        $timeout = config('shopify.circuit_breaker.timeout_seconds', 60);
        
        Cache::put($this->getStateKey($breakerName), self::STATE_OPEN, now()->addSeconds($timeout * 2));
        Cache::put($this->getOpenedAtKey($breakerName), time(), now()->addSeconds($timeout * 2));
        Cache::forget($this->getSuccessCountKey($breakerName));

        $this->logCircuitBreakerTransition($breakerName, self::STATE_OPEN);
    }

    /**
     * Transition circuit breaker to HALF_OPEN state
     *
     * @param string $breakerName
     * @return void
     */
    protected function transitionToHalfOpen(string $breakerName): void
    {
        Cache::put($this->getStateKey($breakerName), self::STATE_HALF_OPEN, now()->addMinutes(5));
        Cache::put($this->getSuccessCountKey($breakerName), 0, now()->addMinutes(5));

        $this->logCircuitBreakerTransition($breakerName, self::STATE_HALF_OPEN);
    }

    /**
     * Configure circuit breaker for the next request
     *
     * @param string $breakerName Circuit breaker identifier
     * @return self
     */
    public function withCircuitBreaker(string $breakerName): self
    {
        $this->circuitBreakerName = $breakerName;
        return $this;
    }

    /**
     * Reset circuit breaker configuration
     *
     * @return void
     */
    protected function resetCircuitBreakerConfig(): void
    {
        $this->circuitBreakerName = null;
    }

    /**
     * Get cache key for circuit breaker state
     *
     * @param string $breakerName
     * @return string
     */
    protected function getStateKey(string $breakerName): string
    {
        return "circuit_breaker:{$breakerName}:state";
    }

    /**
     * Get cache key for failure count
     *
     * @param string $breakerName
     * @return string
     */
    protected function getFailureCountKey(string $breakerName): string
    {
        return "circuit_breaker:{$breakerName}:failures";
    }

    /**
     * Get cache key for success count
     *
     * @param string $breakerName
     * @return string
     */
    protected function getSuccessCountKey(string $breakerName): string
    {
        return "circuit_breaker:{$breakerName}:successes";
    }

    /**
     * Get cache key for opened timestamp
     *
     * @param string $breakerName
     * @return string
     */
    protected function getOpenedAtKey(string $breakerName): string
    {
        return "circuit_breaker:{$breakerName}:opened_at";
    }

    /**
     * Get cache key for window start timestamp
     *
     * @param string $breakerName
     * @return string
     */
    protected function getWindowStartKey(string $breakerName): string
    {
        return "circuit_breaker:{$breakerName}:window_start";
    }

    /**
     * Log circuit breaker state transition
     *
     * @param string $breakerName
     * @param string $newState
     * @return void
     */
    protected function logCircuitBreakerTransition(string $breakerName, string $newState): void
    {
        $correlationId = request()->header('X-Correlation-ID') ?? 'unknown';

        Log::channel('shopify')->warning('Circuit breaker state transition', [
            'correlation_id' => $correlationId,
            'breaker_name' => $breakerName,
            'new_state' => $newState,
            'timestamp' => now()->toIso8601String(),
        ]);
    }

    /**
     * Log circuit breaker open event
     *
     * @param string $breakerName
     * @return void
     */
    protected function logCircuitBreakerOpen(string $breakerName): void
    {
        $correlationId = request()->header('X-Correlation-ID') ?? 'unknown';

        Log::channel('error')->error('Circuit breaker is open', [
            'correlation_id' => $correlationId,
            'breaker_name' => $breakerName,
            'message' => 'Request blocked by circuit breaker',
            'timestamp' => now()->toIso8601String(),
        ]);
    }
}
