<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\RateLimitMiddleware;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class RateLimitMiddlewareTest extends TestCase
{
    protected RateLimiter $limiter;
    protected RateLimitMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->limiter = app(RateLimiter::class);
        $this->middleware = new RateLimitMiddleware($this->limiter);
        
        // Enable rate limiting for tests
        config(['shopify.rate_limit.enabled' => true]);
        config(['shopify.rate_limit.max_attempts' => 5]);
        config(['shopify.rate_limit.decay_minutes' => 1]);
    }

    protected function tearDown(): void
    {
        // Clear rate limiter state
        $this->limiter->clear('rate_limit:ip:' . sha1('127.0.0.1'));
        parent::tearDown();
    }

    public function test_allows_request_within_rate_limit(): void
    {
        $request = Request::create('/test', 'GET');
        
        $response = $this->middleware->handle($request, function ($req) {
            return new Response('OK', 200);
        });

        $this->assertEquals(200, $response->getStatusCode());
        $this->assertTrue($response->headers->has('X-RateLimit-Limit'));
        $this->assertTrue($response->headers->has('X-RateLimit-Remaining'));
    }

    public function test_blocks_request_when_rate_limit_exceeded(): void
    {
        $request = Request::create('/test', 'GET');
        
        // Make requests up to the limit
        for ($i = 0; $i < 5; $i++) {
            $this->middleware->handle($request, function ($req) {
                return new Response('OK', 200);
            });
        }
        
        // Next request should be blocked
        $response = $this->middleware->handle($request, function ($req) {
            return new Response('OK', 200);
        });

        $this->assertEquals(429, $response->getStatusCode());
        
        $content = json_decode($response->getContent(), true);
        $this->assertFalse($content['success']);
        $this->assertStringContainsString('Too many requests', $content['message']);
        $this->assertEquals('RATE_LIMIT_EXCEEDED', $content['meta']['error_code']);
    }

    public function test_adds_rate_limit_headers_to_response(): void
    {
        $request = Request::create('/test', 'GET');
        
        $response = $this->middleware->handle($request, function ($req) {
            return new Response('OK', 200);
        });

        $this->assertEquals('5', $response->headers->get('X-RateLimit-Limit'));
        $this->assertEquals('4', $response->headers->get('X-RateLimit-Remaining'));
    }

    public function test_skips_rate_limiting_when_disabled(): void
    {
        config(['shopify.rate_limit.enabled' => false]);
        
        $request = Request::create('/test', 'GET');
        
        // Make many requests
        for ($i = 0; $i < 10; $i++) {
            $response = $this->middleware->handle($request, function ($req) {
                return new Response('OK', 200);
            });
            
            $this->assertEquals(200, $response->getStatusCode());
        }
    }

    public function test_rate_limit_response_includes_retry_after(): void
    {
        $request = Request::create('/test', 'GET');
        
        // Exceed rate limit
        for ($i = 0; $i < 6; $i++) {
            $response = $this->middleware->handle($request, function ($req) {
                return new Response('OK', 200);
            });
        }

        $this->assertTrue($response->headers->has('Retry-After'));
        $this->assertTrue($response->headers->has('X-RateLimit-Reset'));
        
        $content = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('retry_after', $content['meta']);
        $this->assertArrayHasKey('retry_after_human', $content['meta']);
    }
}
