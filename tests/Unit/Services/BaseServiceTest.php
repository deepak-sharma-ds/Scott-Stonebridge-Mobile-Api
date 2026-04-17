<?php

namespace Tests\Unit\Services;

use App\Logging\CorrelationIdProcessor;
use App\Services\Base\BaseService;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class BaseServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Reset correlation ID before each test
        CorrelationIdProcessor::reset();
    }

    protected function tearDown(): void
    {
        // Reset correlation ID after each test
        CorrelationIdProcessor::reset();

        parent::tearDown();
    }

    /** @test */
    public function it_resolves_correlation_id_from_processor()
    {
        // Set correlation ID in processor
        $expectedId = 'test-correlation-id-123';
        CorrelationIdProcessor::setCorrelationId($expectedId);

        $service = new ConcreteTestService();

        $this->assertEquals($expectedId, $this->getProtectedProperty($service, 'correlationId'));
    }

    /** @test */
    public function it_resolves_correlation_id_from_request_header()
    {
        $expectedId = 'request-correlation-id-456';

        // Create a request with the correlation ID header
        $request = \Illuminate\Http\Request::create('/test', 'GET');
        $request->headers->set('X-Correlation-ID', $expectedId);
        app()->instance('request', $request);

        $service = new ConcreteTestService();

        $this->assertEquals($expectedId, $this->getProtectedProperty($service, 'correlationId'));
    }

    /** @test */
    public function it_generates_correlation_id_when_not_available()
    {
        $service = new ConcreteTestService();

        $correlationId = $this->getProtectedProperty($service, 'correlationId');

        $this->assertNotNull($correlationId);
        $this->assertIsString($correlationId);
        // UUID v4 format check
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $correlationId
        );
    }

    /** @test */
    public function it_sets_service_name_from_class_name()
    {
        $service = new ConcreteTestService();

        $serviceName = $this->getProtectedProperty($service, 'serviceName');

        $this->assertEquals('ConcreteTestService', $serviceName);
    }

    /** @test */
    public function it_logs_info_with_service_context()
    {
        Log::shouldReceive('channel')
            ->with('api')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $level === 'info'
                    && $message === 'Test info message'
                    && isset($context['service'])
                    && $context['service'] === 'ConcreteTestService'
                    && isset($context['correlation_id'])
                    && isset($context['timestamp'])
                    && isset($context['custom_key'])
                    && $context['custom_key'] === 'custom_value';
            });

        $service = new ConcreteTestService();
        $service->testLogInfo('Test info message', ['custom_key' => 'custom_value']);
    }

    /** @test */
    public function it_logs_warning_with_service_context()
    {
        Log::shouldReceive('channel')
            ->with('api')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $level === 'warning'
                    && $message === 'Test warning message'
                    && isset($context['service'])
                    && isset($context['correlation_id']);
            });

        $service = new ConcreteTestService();
        $service->testLogWarning('Test warning message');
    }

    /** @test */
    public function it_logs_error_with_service_context()
    {
        Log::shouldReceive('channel')
            ->with('api')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $level === 'error'
                    && $message === 'Test error message'
                    && isset($context['service'])
                    && isset($context['correlation_id']);
            });

        $service = new ConcreteTestService();
        $service->testLogError('Test error message');
    }

    /** @test */
    public function it_logs_debug_with_service_context()
    {
        Log::shouldReceive('channel')
            ->with('api')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $level === 'debug'
                    && $message === 'Test debug message'
                    && isset($context['service'])
                    && isset($context['correlation_id']);
            });

        $service = new ConcreteTestService();
        $service->testLogDebug('Test debug message');
    }

    /** @test */
    public function it_logs_to_custom_channel()
    {
        Log::shouldReceive('channel')
            ->with('shopify')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $level === 'info'
                    && $message === 'Test custom channel';
            });

        $service = new ConcreteTestService();
        $service->testLogInfo('Test custom channel', [], 'shopify');
    }

    /** @test */
    public function it_logs_performance_metrics()
    {
        Log::shouldReceive('channel')
            ->with('performance')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $level === 'info'
                    && $message === 'Performance: test_operation'
                    && $context['operation'] === 'test_operation'
                    && $context['duration_ms'] === 150.5
                    && isset($context['service'])
                    && isset($context['correlation_id']);
            });

        $service = new ConcreteTestService();
        $service->testLogPerformance('test_operation', 150.5);
    }

    /** @test */
    public function it_logs_performance_with_additional_metrics()
    {
        Log::shouldReceive('channel')
            ->with('performance')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $context['operation'] === 'test_operation'
                    && $context['duration_ms'] === 200.0
                    && $context['query_count'] === 5
                    && $context['cache_hits'] === 3;
            });

        $service = new ConcreteTestService();
        $service->testLogPerformance('test_operation', 200.0, [
            'query_count' => 5,
            'cache_hits' => 3,
        ]);
    }

    /** @test */
    public function it_executes_callback_with_performance_logging_on_success()
    {
        Log::shouldReceive('channel')
            ->with('performance')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $context['operation'] === 'test_operation'
                    && isset($context['duration_ms'])
                    && $context['status'] === 'success';
            });

        $service = new ConcreteTestService();
        $result = $service->testWithPerformanceLogging('test_operation', function () {
            return 'success_result';
        });

        $this->assertEquals('success_result', $result);
    }

    /** @test */
    public function it_executes_callback_with_performance_logging_on_error()
    {
        Log::shouldReceive('channel')
            ->with('performance')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $context['operation'] === 'test_operation'
                    && isset($context['duration_ms'])
                    && $context['status'] === 'error'
                    && $context['error'] === 'Test exception';
            });

        $service = new ConcreteTestService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Test exception');

        $service->testWithPerformanceLogging('test_operation', function () {
            throw new \RuntimeException('Test exception');
        });
    }

    /** @test */
    public function it_logs_exception_with_full_context()
    {
        Log::shouldReceive('channel')
            ->with('error')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $level === 'error'
                    && str_contains($message, 'Exception in test_operation')
                    && $context['operation'] === 'test_operation'
                    && $context['exception'] === \RuntimeException::class
                    && $context['message'] === 'Test exception message'
                    && isset($context['file'])
                    && isset($context['line'])
                    && isset($context['trace'])
                    && isset($context['service'])
                    && isset($context['correlation_id']);
            });

        $service = new ConcreteTestService();
        $exception = new \RuntimeException('Test exception message');

        $service->testLogException($exception, 'test_operation');
    }

    /** @test */
    public function it_logs_exception_with_additional_context()
    {
        Log::shouldReceive('channel')
            ->with('error')
            ->once()
            ->andReturnSelf();

        Log::shouldReceive('log')
            ->once()
            ->withArgs(function ($level, $message, $context) {
                return $context['operation'] === 'test_operation'
                    && $context['user_id'] === 123
                    && $context['request_data'] === 'test_data';
            });

        $service = new ConcreteTestService();
        $exception = new \RuntimeException('Test exception');

        $service->testLogException($exception, 'test_operation', [
            'user_id' => 123,
            'request_data' => 'test_data',
        ]);
    }

    /** @test */
    public function it_builds_log_context_with_defaults()
    {
        $service = new ConcreteTestService();
        $context = $service->testBuildLogContext(['custom' => 'value']);

        $this->assertArrayHasKey('service', $context);
        $this->assertArrayHasKey('correlation_id', $context);
        $this->assertArrayHasKey('timestamp', $context);
        $this->assertArrayHasKey('custom', $context);
        $this->assertEquals('ConcreteTestService', $context['service']);
        $this->assertEquals('value', $context['custom']);
    }

    /**
     * Helper method to access protected properties
     */
    protected function getProtectedProperty($object, $property)
    {
        $reflection = new \ReflectionClass($object);
        $property = $reflection->getProperty($property);
        $property->setAccessible(true);
        return $property->getValue($object);
    }
}

/**
 * Concrete test service for testing BaseService
 */
class ConcreteTestService extends BaseService
{
    public function testLogInfo(string $message, array $context = [], ?string $channel = null): void
    {
        $this->logInfo($message, $context, $channel);
    }

    public function testLogWarning(string $message, array $context = [], ?string $channel = null): void
    {
        $this->logWarning($message, $context, $channel);
    }

    public function testLogError(string $message, array $context = [], ?string $channel = null): void
    {
        $this->logError($message, $context, $channel);
    }

    public function testLogDebug(string $message, array $context = [], ?string $channel = null): void
    {
        $this->logDebug($message, $context, $channel);
    }

    public function testLogPerformance(string $operation, float $duration, array $additionalMetrics = []): void
    {
        $this->logPerformance($operation, $duration, $additionalMetrics);
    }

    public function testWithPerformanceLogging(string $operation, callable $callback, array $additionalMetrics = []): mixed
    {
        return $this->withPerformanceLogging($operation, $callback, $additionalMetrics);
    }

    public function testLogException(\Throwable $exception, string $operation, array $context = []): void
    {
        $this->logException($exception, $operation, $context);
    }

    public function testBuildLogContext(array $context = []): array
    {
        return $this->buildLogContext($context);
    }
}

