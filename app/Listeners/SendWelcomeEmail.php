<?php

namespace App\Listeners;

use App\Events\CustomerRegistered;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendWelcomeEmail implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Handle the event.
     */
    public function handle(CustomerRegistered $event): void
    {
        // For now, we just log. In future, trigger Mail notification.
        Log::info("🎉 Welcome Email Triggered for: {$event->customer->email} ({$event->customer->firstName})");
        
        // Example: Mail::to($event->customer->email)->send(new WelcomeMail($event->customer));
    }
}
