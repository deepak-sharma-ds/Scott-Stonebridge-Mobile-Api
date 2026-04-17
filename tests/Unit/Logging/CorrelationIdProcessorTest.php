<?php

namespace Tests\Unit\Logging;

use App\Logging\CorrelationIdProcessor;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class CorrelationIdProcessorTest extends TestCase
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

    public function test_processor_adds_correlation_id_to_log_record(): void
    {
        $processor = new CorrelationIdProcessor();
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: [],
            extra: []
        );

        $processedRecord = $processor($record);

        $this->assertArrayHasKey('correlation_id', $processedRecord->extra);
        $this->assertNotEmpty($processedRecord->extra['correlation_id']);
        $this->assertIsString($processedRecord->extra['correlation_id']);
    }

    public function test_processor_uses_same_correlation_id_for_multiple_records(): void
    {
        $processor = new CorrelationIdProcessor();
        
        $record1 = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'First message',
            context: [],
            extra: []
        );

        $record2 = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Second message',
            context: [],
            extra: []
        );

        $processedRecord1 = $processor($record1);
        $processedRecord2 = $processor($record2);

        $this->assertEquals(
            $processedRecord1->extra['correlation_id'],
            $processedRecord2->extra['correlation_id']
        );
    }

    public function test_processor_uses_header_correlation_id_when_available(): void
    {
        $expectedCorrelationId = 'test-correlation-id-123';
        
        // Set correlation ID manually (simulating what middleware would do)
        CorrelationIdProcessor::setCorrelationId($expectedCorrelationId);
        
        $processor = new CorrelationIdProcessor();
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: [],
            extra: []
        );

        $processedRecord = $processor($record);

        $this->assertEquals($expectedCorrelationId, $processedRecord->extra['correlation_id']);
    }

    public function test_set_correlation_id_manually(): void
    {
        $expectedCorrelationId = 'manual-correlation-id-456';
        
        CorrelationIdProcessor::setCorrelationId($expectedCorrelationId);
        
        $processor = new CorrelationIdProcessor();
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: [],
            extra: []
        );

        $processedRecord = $processor($record);

        $this->assertEquals($expectedCorrelationId, $processedRecord->extra['correlation_id']);
    }

    public function test_get_current_correlation_id(): void
    {
        $processor = new CorrelationIdProcessor();
        
        $record = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Test message',
            context: [],
            extra: []
        );

        $processedRecord = $processor($record);
        $currentId = CorrelationIdProcessor::getCurrentCorrelationId();

        $this->assertEquals($processedRecord->extra['correlation_id'], $currentId);
    }

    public function test_reset_clears_correlation_id(): void
    {
        $processor = new CorrelationIdProcessor();
        
        $record1 = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'First message',
            context: [],
            extra: []
        );

        $processedRecord1 = $processor($record1);
        $firstId = $processedRecord1->extra['correlation_id'];

        // Reset and create new processor
        CorrelationIdProcessor::reset();
        $processor2 = new CorrelationIdProcessor();
        
        $record2 = new LogRecord(
            datetime: new \DateTimeImmutable(),
            channel: 'test',
            level: Level::Info,
            message: 'Second message',
            context: [],
            extra: []
        );

        $processedRecord2 = $processor2($record2);
        $secondId = $processedRecord2->extra['correlation_id'];

        $this->assertNotEquals($firstId, $secondId);
    }
}
