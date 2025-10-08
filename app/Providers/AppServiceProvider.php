<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use App\Models\Configuration;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if (app()->environment('staging', 'production')) {
            URL::forceScheme('https');
        }
        Paginator::useBootstrapFive(); // Use Bootstrap 5

        $this->configHandler();
    }

    /*
    * Load all configuration data
    */
    private function configHandler()
    {
        try {
            \DB::connection()->getPdo();

            if (\Schema::hasTable('configurations')) {
                $configuration = new Configuration();
                $configuration->init();
            }
        } catch (\Exception $e) {
            \Log::info('Configuration is not loaded.');
        }
    }
}
