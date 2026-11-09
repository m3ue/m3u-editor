<?php

use App\Listeners\PersistUserLocale;
use App\Notifications\TelegramAlert;
use App\Settings\GeneralSettings;
use CraftForge\FilamentLanguageSwitcher\Events\LocaleChanged;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;

/**
 * Listeners in app/Listeners are registered by Laravel's event discovery.
 * Registering one by hand as well adds it a second time (as Class and
 * Class@handle), so every event ran it twice.
 */
it('runs the failed job alert listener once per failed job', function () {
    Notification::fake();

    $settings = Mockery::mock(GeneralSettings::class);
    foreach ([
        'discord_alerts_enabled' => false,
        'discord_webhook_url' => null,
        'slack_alerts_enabled' => false,
        'slack_webhook_url' => null,
        'telegram_alerts_enabled' => true,
        'telegram_bot_token' => Crypt::encryptString('test-token'),
        'telegram_chat_id' => '123456789',
        'alerts_on_job_failed' => true,
    ] as $key => $value) {
        $settings->{$key} = $value;
    }
    app()->instance(GeneralSettings::class, $settings);

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\ProcessM3uImport');
    $job->shouldReceive('getQueue')->andReturn('default');
    $job->shouldIgnoreMissing();

    event(new JobFailed('redis', $job, new Exception('boom')));

    Notification::assertSentOnDemandTimes(TelegramAlert::class, 1);
});

it('runs the locale listener once per locale change', function () {
    $listener = Mockery::mock(PersistUserLocale::class);
    $listener->shouldReceive('handle')->once();
    app()->instance(PersistUserLocale::class, $listener);

    event(new LocaleChanged('fr', 'en'));
});
