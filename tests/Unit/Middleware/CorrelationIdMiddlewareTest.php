<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\CorrelationIdMiddleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Tests\TestCase;

class CorrelationIdMiddlewareTest extends TestCase
{
    protected CorrelationIdMiddleware $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new CorrelationIdMiddleware();
    }

    public function test_generates_correlation_id_when_not_provided(): void
    {
        $request = Request::create('/test', 'GET');
        
        $response = $this->middleware->handle($request, function ($req) {
            $this->assertNotEmpty($req->attributes->get('correlation_id'));
            $this->assertNotEmpty($req->input('correlation_id'));
            return new Response('OK');
        });

        $this->assertTrue($response->headers->has('X-Correlation-ID'));
        $this->assertNotEmpty($response->headers->get('X-Correlation-ID'));
    }

    public function test_uses_existing_correlation_id_from_header(): void
    {
        $correlationId = 'test-correlation-id-123';
        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Correlation-ID', $correlationId);
        
        $response = $this->middleware->handle($request, function ($req) use ($correlationId) {
            $this->assertEquals($correlationId, $req->attributes->get('correlation_id'));
            $this->assertEquals($correlationId, $req->input('correlation_id'));
            return new Response('OK');
        });

        $this->assertEquals($correlationId, $response->headers->get('X-Correlation-ID'));
    }

    public function test_uses_x_request_id_as_fallback(): void
    {
        $requestId = 'test-request-id-456';
        $request = Request::create('/test', 'GET');
        $request->headers->set('X-Request-ID', $requestId);
        
        $response = $this->middleware->handle($request, function ($req) use ($requestId) {
            $this->assertEquals($requestId, $req->attributes->get('correlation_id'));
            return new Response('OK');
        });

        $this->assertEquals($requestId, $response->headers->get('X-Correlation-ID'));
    }

    public function test_correlation_id_is_uuid_format_when_generated(): void
    {
        $request = Request::create('/test', 'GET');
        
        $this->middleware->handle($request, function ($req) {
            $correlationId = $req->attributes->get('correlation_id');
            // UUID v4 format: xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $correlationId
            );
            return new Response('OK');
        });
    }
}
