<?php

declare(strict_types=1);

namespace App\Services\AI;

use App\Contracts\Services\AI\SafetyServiceInterface;
use App\Exceptions\AI\AIRateLimitException;
use App\Exceptions\AI\AISafetyViolationException;
use App\Services\Base\BaseService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Sanitizes inbound messages, runs cheap pattern-based jailbreak detection,
 * and tracks per-session + per-IP cooldown counters. Uses the configured
 * cache store (Redis in production) keyed under `ai:safety:*`.
 *
 * This sits in FRONT of any OpenAI call to avoid spending tokens on payloads
 * that would only be rejected by the API anyway.
 */
class SafetyService extends BaseService implements SafetyServiceInterface
{
    public function sanitize(string $message): string
    {
        $maxLength = (int) config('chatbot.message.max_length', 2000);
        $stripHtml = (bool) config('chatbot.safety.strip_html', true);

        $clean = $message;

        // Strip HTML tags (also nukes <script>, <iframe>, etc.).
        if ($stripHtml) {
            $clean = strip_tags($clean);
        }

        // Remove all control characters except newline + tab.
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean) ?? $clean;

        // Normalize unicode (NFKC) when intl is available.
        if (config('chatbot.safety.normalize_unicode') && class_exists(\Normalizer::class)) {
            $normalized = \Normalizer::normalize($clean, \Normalizer::FORM_KC);
            if (is_string($normalized)) {
                $clean = $normalized;
            }
        }

        // Collapse excessive whitespace.
        $clean = trim((string) preg_replace('/\s+/u', ' ', $clean));

        // Hard length cap.
        if (Str::length($clean) > $maxLength) {
            $clean = (string) Str::limit($clean, $maxLength, '');
        }

        return $clean;
    }

    public function assertSafe(string $sanitizedMessage): void
    {
        if ($sanitizedMessage === '') {
            throw new AISafetyViolationException('Empty message after sanitization.', [
                'reason' => 'empty',
            ]);
        }

        $patterns = (array) config('chatbot.safety.banned_patterns', []);
        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }
            if (@preg_match($pattern, $sanitizedMessage) === 1) {
                $this->logWarning('Safety filter hit', [
                    'pattern' => $pattern,
                ], 'ai');

                throw new AISafetyViolationException('Message blocked by safety filters.', [
                    'pattern' => $pattern,
                ]);
            }
        }
    }

    public function assertWithinLimits(string $sessionId, ?string $ip): void
    {
        $perSession = (int) config('chatbot.rate_limits.per_session_per_minute', 20);
        $perIp = (int) config('chatbot.rate_limits.per_ip_per_minute', 60);
        $perDay = (int) config('chatbot.rate_limits.per_ip_per_day', 1000);

        $this->bump('ai:safety:session:'.$sessionId, $perSession, 60, 'session');
        if ($ip !== null && $ip !== '') {
            $this->bump('ai:safety:ip:'.$ip, $perIp, 60, 'ip');
            $this->bump('ai:safety:ip-day:'.$ip, $perDay, 86400, 'ip-daily');
        }
    }

    private function bump(string $key, int $limit, int $ttlSeconds, string $bucket): void
    {
        $current = (int) Cache::get($key, 0);
        if ($current >= $limit) {
            throw new AIRateLimitException('Chat rate limit exceeded.', [
                'bucket' => $bucket,
                'limit' => $limit,
                'ttl' => $ttlSeconds,
            ]);
        }

        // Cache::increment doesn't set TTL on first write — handle both branches.
        if ($current === 0) {
            Cache::put($key, 1, $ttlSeconds);
        } else {
            Cache::increment($key);
        }
    }
}
