<?php

namespace App\Providers;

use App\Settings\GeneralSettings;
use Exception;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     */
    protected function gate(): void
    {
        $userPreferences = app(GeneralSettings::class);
        try {
            $enableQueueManager = $userPreferences->show_queue_manager;
        } catch (Exception $e) {
            $enableQueueManager = false;
        }
        Gate::define('viewHorizon', fn ($user) => $user && $enableQueueManager);
    }
}
