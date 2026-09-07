<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Contracts\Services\PushNotificationServiceInterface;
use App\Exceptions\PushTokenInvalidException;
use App\Jobs\Push\SendPushNotificationJob;
use App\Jobs\Push\SendPushToRecipientJob;
use App\Models\DeviceToken;
use App\Models\PushNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SendPushJobsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['push.enabled' => true, 'push.test_emails' => [], 'push.log_skipped' => false]);
    }

    protected function makeRecipientJob(string $email): SendPushToRecipientJob
    {
        return new SendPushToRecipientJob(
            $email,
            PushNotification::SOURCE_FLOW,
            'FLOW1',
            'MSG1',
            'Title',
            'Body',
            'app://home'
        );
    }

    public function test_recipient_with_multiple_devices_creates_row_per_device(): void
    {
        Queue::fake();
        DeviceToken::factory()->count(2)->create(['customer_email' => 'multi@example.com']);

        $this->makeRecipientJob('multi@example.com')->handle();

        $this->assertSame(2, PushNotification::count());
        Queue::assertPushed(SendPushNotificationJob::class, 2);
    }

    public function test_recipient_with_no_device_is_skipped_silently(): void
    {
        Queue::fake();

        $this->makeRecipientJob('ghost@example.com')->handle();

        $this->assertSame(0, PushNotification::count());
        Queue::assertNothingPushed();
    }

    public function test_opted_out_device_is_ignored(): void
    {
        Queue::fake();
        DeviceToken::factory()->optedOut()->create(['customer_email' => 'quiet@example.com']);

        $this->makeRecipientJob('quiet@example.com')->handle();

        $this->assertSame(0, PushNotification::count());
        Queue::assertNothingPushed();
    }

    public function test_recipient_job_stores_full_content_when_provided(): void
    {
        Queue::fake();
        DeviceToken::factory()->create(['customer_email' => 'full@example.com']);

        (new SendPushToRecipientJob(
            'full@example.com',
            PushNotification::SOURCE_CAMPAIGN,
            'CAMP1',
            'CAMP1',
            'Title',
            'Body',
            'app://home',
            'The full email body, in full.'
        ))->handle();

        $notification = PushNotification::first();
        $this->assertSame('The full email body, in full.', $notification->data['content'] ?? null);
        $this->assertSame('app://home', $notification->data['deep_link'] ?? null);
    }

    public function test_recipient_job_omits_content_key_when_none_available(): void
    {
        Queue::fake();
        DeviceToken::factory()->create(['customer_email' => 'nocontent@example.com']);

        $this->makeRecipientJob('nocontent@example.com')->handle();

        $notification = PushNotification::first();
        $this->assertArrayNotHasKey('content', (array) $notification->data);
    }

    public function test_recipient_fanout_is_idempotent_on_repeat(): void
    {
        Queue::fake();
        DeviceToken::factory()->create(['customer_email' => 'once@example.com']);

        $this->makeRecipientJob('once@example.com')->handle();
        $this->makeRecipientJob('once@example.com')->handle();

        $this->assertSame(1, PushNotification::count());
        Queue::assertPushed(SendPushNotificationJob::class, 1);
    }

    public function test_send_job_marks_sent_on_success(): void
    {
        $notification = PushNotification::factory()->create(['status' => PushNotification::STATUS_PENDING]);

        $service = Mockery::mock(PushNotificationServiceInterface::class);
        $service->shouldReceive('send')->once()->andReturn('projects/x/messages/1');

        (new SendPushNotificationJob($notification->id))->handle($service);

        $this->assertSame(PushNotification::STATUS_SENT, $notification->fresh()->status);
        $this->assertSame('projects/x/messages/1', $notification->fresh()->fcm_message_id);
    }

    public function test_send_job_revokes_token_on_invalid_and_does_not_throw(): void
    {
        $device = DeviceToken::factory()->create();
        $notification = PushNotification::factory()->create([
            'device_token_id' => $device->id,
            'status' => PushNotification::STATUS_PENDING,
        ]);

        $service = Mockery::mock(PushNotificationServiceInterface::class);
        $service->shouldReceive('send')->once()->andThrow(new PushTokenInvalidException('unregistered'));

        (new SendPushNotificationJob($notification->id))->handle($service);

        $this->assertNotNull($device->fresh()->revoked_at);
        $this->assertSame(PushNotification::STATUS_FAILED, $notification->fresh()->status);
        $this->assertSame('token_invalid', $notification->fresh()->error_code);
    }

    public function test_send_job_rethrows_transient_error_for_retry(): void
    {
        $notification = PushNotification::factory()->create(['status' => PushNotification::STATUS_PENDING]);

        $service = Mockery::mock(PushNotificationServiceInterface::class);
        $service->shouldReceive('send')->once()->andThrow(new RuntimeException('fcm unavailable'));

        $this->expectException(RuntimeException::class);

        (new SendPushNotificationJob($notification->id))->handle($service);
    }

    public function test_send_job_noop_when_already_sent(): void
    {
        $notification = PushNotification::factory()->sent()->create();

        $service = Mockery::mock(PushNotificationServiceInterface::class);
        $service->shouldNotReceive('send');

        (new SendPushNotificationJob($notification->id))->handle($service);

        $this->assertTrue(true);
    }
}
