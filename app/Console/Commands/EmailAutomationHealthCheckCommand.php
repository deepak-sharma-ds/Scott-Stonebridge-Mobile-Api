<?php

namespace App\Console\Commands;

use App\Services\CampaignEmail\CampaignDeliveryLifecycle;
use App\Services\EmailReading\EmailReadingDeliveryLifecycle;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Detects a dead scheduler: deliveries that were due well past the grace
 * period but were never claimed by the dispatcher. Logs critically and emails
 * the admin at most once per hour.
 */
class EmailAutomationHealthCheckCommand extends Command
{
    protected $signature = 'email-automation:health {--grace=10 : Minutes a due delivery may wait before it counts as overdue}';

    protected $description = 'Alert when due reading/campaign deliveries are not being dispatched (scheduler or worker down)';

    public function handle(EmailReadingDeliveryLifecycle $readings, CampaignDeliveryLifecycle $campaigns): int
    {
        $grace = max(1, (int) $this->option('grace'));
        $overdueReadings = $readings->overdueCount($grace);
        $overdueCampaigns = $campaigns->overdueCount($grace);

        if ($overdueReadings + $overdueCampaigns === 0) {
            $this->info('OK: no overdue deliveries.');

            return self::SUCCESS;
        }

        $message = "Overdue deliveries not dispatched for {$grace}+ minutes: {$overdueReadings} readings, {$overdueCampaigns} campaign emails. Check that the scheduler (schedule:run) is running.";

        Log::channel('shopify_webhooks')->critical($message);
        $this->error($message);

        if (Cache::add('email-automation:health-alerted', true, now()->addHour())) {
            $admin = (string) config('email_reading.admin_notify_email');

            if ($admin !== '') {
                try {
                    Mail::raw($message, fn ($mail) => $mail->to($admin)->subject('[Scott Stonebridge] Email automation is not dispatching'));
                } catch (Throwable $e) {
                    Log::channel('shopify_webhooks')->error('Health-check alert mail failed', ['error' => $e->getMessage()]);
                }
            }
        }

        return self::FAILURE;
    }
}
