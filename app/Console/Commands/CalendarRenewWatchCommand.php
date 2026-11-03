<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Mail\CalendarWatchRenewalFailedMail;
use App\Models\Configuration;
use App\Services\BookingService;
use Carbon\Carbon;
use Google_Service_Calendar_Channel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class CalendarRenewWatchCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'calendar:renew-watch {--force : Force renewal regardless of expiration}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check and renew Google Calendar watch subscriptions before 7-day TTL expiration';

    /**
     * Execute the console command.
     */
    public function handle(BookingService $bookingService): int
    {
        $log = Log::channel('appointment_slots');
        $this->info('Checking Google Calendar watch channel subscriptions...');

        $calendarService = $bookingService->getGoogleCalendarService();
        if (! $calendarService) {
            $this->error('Google Calendar credentials missing or expired. Cannot renew watch channels.');
            $this->sendAlert('All Calendars', 'Google Calendar credentials missing or expired. Admin re-authentication required.');

            return self::FAILURE;
        }

        $slotsCalendarId = $bookingService->getSlotsCalendarId()
            ?: $bookingService->getOrCreateSubCalendar('Stonebridge - Available Slots');

        $calendars = [
            [
                'name' => 'Primary Calendar',
                'id' => 'primary',
                'prefix' => 'primary',
            ],
        ];

        if ($slotsCalendarId) {
            $calendars[] = [
                'name' => 'Sub-Calendar (Available Slots)',
                'id' => $slotsCalendarId,
                'prefix' => 'slots',
            ];
        }

        $force = (bool) $this->option('force');
        $hasFailure = false;

        foreach ($calendars as $cal) {
            $name = $cal['name'];
            $calId = $cal['id'];
            $prefix = $cal['prefix'];

            $expiration = Configuration::where('name', "GoogleCalendar.{$prefix}_channel_expiration")->value('value');
            $channelId = Configuration::where('name', "GoogleCalendar.{$prefix}_channel_id")->value('value');
            $resourceId = Configuration::where('name', "GoogleCalendar.{$prefix}_resource_id")->value('value');

            if (! $this->isExpiringSoon($expiration, $force)) {
                $this->info("Channel for {$name} is still valid. Skipping renewal.");

                continue;
            }

            $this->info("Renewing watch channel for {$name} ({$calId})...");

            try {
                // Optionally stop the old channel
                if (! empty($channelId) && ! empty($resourceId)) {
                    try {
                        $stopChannel = new Google_Service_Calendar_Channel;
                        $stopChannel->setId((string) $channelId);
                        $stopChannel->setResourceId((string) $resourceId);
                        $calendarService->channels->stop($stopChannel);
                    } catch (Throwable $stopException) {
                        $log->info("Could not stop old channel {$channelId}: {$stopException->getMessage()}");
                    }
                }

                $newChannelId = (string) Str::uuid();
                $webhookUrl = config('booking.google_webhook_url') ?: url('/webhook/google-calendar');

                $channel = new Google_Service_Calendar_Channel;
                $channel->setId($newChannelId);
                $channel->setType('web_hook');
                $channel->setAddress($webhookUrl);

                $channelToken = config('booking.google_channel_token');
                if (! empty($channelToken)) {
                    $channel->setToken((string) $channelToken);
                }

                $watchResponse = $calendarService->events->watch($calId, $channel);

                Configuration::updateOrCreate(
                    ['name' => "GoogleCalendar.{$prefix}_channel_id"],
                    ['value' => $watchResponse->getId(), 'editable' => 0]
                );
                Configuration::updateOrCreate(
                    ['name' => "GoogleCalendar.{$prefix}_resource_id"],
                    ['value' => $watchResponse->getResourceId(), 'editable' => 0]
                );
                Configuration::updateOrCreate(
                    ['name' => "GoogleCalendar.{$prefix}_channel_expiration"],
                    ['value' => (string) $watchResponse->getExpiration(), 'editable' => 0]
                );

                $this->info("Successfully renewed watch channel for {$name}. Channel ID: {$watchResponse->getId()}");
                $log->info("Successfully renewed watch channel for {$name}", [
                    'channel_id' => $watchResponse->getId(),
                    'resource_id' => $watchResponse->getResourceId(),
                    'expiration' => $watchResponse->getExpiration(),
                ]);
            } catch (Throwable $e) {
                $hasFailure = true;
                $this->error("Failed to renew watch channel for {$name}: {$e->getMessage()}");
                $log->error("Failed to renew watch channel for {$name}: {$e->getMessage()}", [
                    'calendar_id' => $calId,
                    'exception' => $e,
                ]);

                $this->sendAlert($name, $e->getMessage());
            }
        }

        return $hasFailure ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Check if a watch channel expiration timestamp is within 48 hours or missing.
     */
    protected function isExpiringSoon(?string $expirationValue, bool $force = false): bool
    {
        if ($force || empty($expirationValue)) {
            return true;
        }

        try {
            if (is_numeric($expirationValue)) {
                $val = (int) $expirationValue;
                $carbon = $val > 10000000000
                    ? Carbon::createFromTimestampMs($val)
                    : Carbon::createFromTimestamp($val);
            } else {
                $carbon = Carbon::parse($expirationValue);
            }

            return $carbon->lte(Carbon::now()->addHours(48));
        } catch (Throwable $e) {
            return true;
        }
    }

    /**
     * Send alert email to Admin upon failure.
     */
    protected function sendAlert(string $calendarName, string $errorMessage): void
    {
        try {
            $adminEmail = config('env.ADMIN_EMAIL') ?: config('mail.from.address', 'admin@example.com');
            if (! empty($adminEmail)) {
                Mail::to($adminEmail)->send(new CalendarWatchRenewalFailedMail($calendarName, $errorMessage));
            }
        } catch (Throwable $e) {
            Log::channel('appointment_slots')->error("Failed sending watch renewal failure alert mail: {$e->getMessage()}");
        }
    }
}
