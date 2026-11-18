<?php

use App\Jobs\SendAlertEmail;
use App\Notifications\TelegramAlert;
use App\Services\AlertService;
use App\Settings\GeneralSettings;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Spatie\DiscordAlerts\Jobs\SendToDiscordChannelJob;
use Spatie\SlackAlerts\Jobs\SendToSlackChannelJob;

beforeEach(function () {
    Notification::fake();
    AlertService::trackRunningJob(null);

    $settings = Mockery::mock(GeneralSettings::class);
    $values = [
        'discord_alerts_enabled' => false,
        'discord_webhook_url' => null,
        'slack_alerts_enabled' => false,
        'slack_webhook_url' => null,
        'telegram_alerts_enabled' => true,
        'telegram_bot_token' => Crypt::encryptString('test-token'),
        'telegram_chat_id' => '123456789',
        'email_alerts_enabled' => false,
        'email_alerts_recipients' => [],
        'alerts_on_job_failed' => true,
    ];

    foreach ($values as $key => $value) {
        $settings->{$key} = $value;
    }

    app()->instance(GeneralSettings::class, $settings);

    // The queue worker reports failed job attempts through the exception
    // handler, which logs them on the default channel.
    config(['logging.default' => 'alerts']);
});

afterEach(function () {
    AlertService::trackRunningJob(null);
});

function alertDeliveryLoopProcessingEvent(string $resolvedName): JobProcessing
{
    // Other JobProcessing listeners (log context, queue monitor) read more of the job.
    $job = Mockery::mock(Job::class)->shouldIgnoreMissing();
    $job->shouldReceive('resolveName')->andReturn($resolvedName);
    $job->shouldReceive('payload')->andReturn([]);

    return new JobProcessing('redis', $job);
}

it('does not raise an alert when the worker reports a failed alert delivery attempt', function (string $deliveryJob) {
    event(alertDeliveryLoopProcessingEvent($deliveryJob));

    report(new RuntimeException('connection refused'));

    Notification::assertNothingSent();
})->with([
    'telegram' => TelegramAlert::class,
    'email' => SendAlertEmail::class,
    'discord' => SendToDiscordChannelJob::class,
    'slack' => SendToSlackChannelJob::class,
]);

it('alerts again once the worker moves on from an alert delivery job', function (string $finishedEvent) {
    event(alertDeliveryLoopProcessingEvent(SendAlertEmail::class));

    event($finishedEvent === Looping::class
        ? new Looping('redis', 'default')
        : new JobProcessed('redis', Mockery::mock(Job::class)->shouldIgnoreMissing()));

    report(new RuntimeException('Playlist sync failed'));

    Notification::assertSentOnDemandTimes(TelegramAlert::class, 1);
})->with([Looping::class, JobProcessed::class]);

it('still alerts on errors reported while an ordinary job runs', function () {
    event(alertDeliveryLoopProcessingEvent('App\\Jobs\\ProcessM3uImport'));

    report(new RuntimeException('Playlist sync failed'));

    Notification::assertSentOnDemandTimes(TelegramAlert::class, 1);
});

it('recognizes every alert delivery job by its resolved queue name', function () {
    foreach (AlertService::DELIVERY_JOBS as $deliveryJob) {
        expect(AlertService::isDeliveryJob($deliveryJob))->toBeTrue();
    }

    expect(AlertService::isDeliveryJob('App\\Jobs\\ProcessM3uImport'))->toBeFalse();
});
