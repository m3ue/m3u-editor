<?php

use App\Listeners\AlertOnJobFailed;
use App\Notifications\TelegramAlert;
use App\Services\AlertService;
use App\Settings\GeneralSettings;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\Telegram\TelegramChannel;

beforeEach(function () {
    Notification::fake();
});

function telegramAlertMockSettings(array $overrides = []): GeneralSettings
{
    $settings = Mockery::mock(GeneralSettings::class);
    $defaults = [
        'discord_alerts_enabled' => false,
        'discord_webhook_url' => null,
        'slack_alerts_enabled' => false,
        'slack_webhook_url' => null,
        'telegram_alerts_enabled' => false,
        'telegram_bot_token' => null,
        'telegram_chat_id' => null,
        'alerts_on_job_failed' => true,
    ];

    foreach (array_merge($defaults, $overrides) as $key => $value) {
        $settings->{$key} = $value;
    }

    app()->instance(GeneralSettings::class, $settings);

    return $settings;
}

function telegramAlertFailedJobEvent(string $resolvedName): JobFailed
{
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn($resolvedName);
    $job->shouldReceive('getQueue')->andReturn('default');

    return new JobFailed('redis', $job, new Exception('boom'));
}

it('sends a telegram alert routed to the configured chat when enabled', function () {
    telegramAlertMockSettings([
        'telegram_alerts_enabled' => true,
        'telegram_bot_token' => Crypt::encryptString('test-token'),
        'telegram_chat_id' => '123456789',
    ]);

    app(AlertService::class)->send('Something went wrong');

    Notification::assertSentOnDemand(
        TelegramAlert::class,
        fn ($notification, $channels, AnonymousNotifiable $notifiable): bool => $notifiable->routes['telegram'] === '123456789'
    );
});

it('does not send a telegram alert when the toggle is disabled', function () {
    telegramAlertMockSettings([
        'telegram_alerts_enabled' => false,
        'telegram_bot_token' => Crypt::encryptString('test-token'),
        'telegram_chat_id' => '123456789',
    ]);

    app(AlertService::class)->send('Something went wrong');

    Notification::assertNothingSent();
});

it('does not send a telegram alert when the bot token or chat id is missing', function (array $overrides) {
    telegramAlertMockSettings(array_merge(['telegram_alerts_enabled' => true], $overrides));

    app(AlertService::class)->send('Something went wrong');

    Notification::assertNothingSent();
})->with([
    'missing bot token' => [['telegram_chat_id' => '123456789']],
    'missing chat id' => [['telegram_bot_token' => 'test-token']],
]);

it('reports telegram as an enabled alert channel only when fully configured', function () {
    telegramAlertMockSettings([
        'telegram_alerts_enabled' => true,
        'telegram_bot_token' => Crypt::encryptString('test-token'),
        'telegram_chat_id' => '123456789',
    ]);
    expect(app(AlertService::class)->isEnabled())->toBeTrue();

    telegramAlertMockSettings([
        'telegram_alerts_enabled' => true,
        'telegram_bot_token' => Crypt::encryptString('test-token'),
    ]);
    expect(app(AlertService::class)->isEnabled())->toBeFalse();
});

it('builds a plain-text telegram message with the configured bot token', function () {
    $token = 'test-token';
    $encryptedToken = Crypt::encryptString($token);

    $notification = new TelegramAlert('[ERROR] Something went wrong', $encryptedToken);

    expect($notification->via(new AnonymousNotifiable))
        ->toBe([TelegramChannel::class]);

    $message = $notification->toTelegram(new AnonymousNotifiable);
    $payload = $message->toArray();

    expect($payload['text'])->toBe('[ERROR] Something went wrong')
        ->and($payload)->not->toHaveKey('parse_mode')
        ->and($message->hasToken())->toBeTrue()
        ->and($message->token)->toBe($token);
});

it('forwards other job failures to telegram via the job failed listener', function () {
    $settings = telegramAlertMockSettings([
        'telegram_alerts_enabled' => true,
        'telegram_bot_token' => Crypt::encryptString('test-token'),
        'telegram_chat_id' => '123456789',
    ]);

    $listener = new AlertOnJobFailed($settings, app(AlertService::class));
    $listener->handle(telegramAlertFailedJobEvent('App\\Jobs\\ProcessM3uImport'));

    Notification::assertSentOnDemand(TelegramAlert::class);
});

it('does not alert on a failed telegram alert delivery to avoid loops', function () {
    $settings = telegramAlertMockSettings([
        'telegram_alerts_enabled' => true,
        'telegram_bot_token' => Crypt::encryptString('test-token'),
        'telegram_chat_id' => '123456789',
    ]);

    $listener = new AlertOnJobFailed($settings, app(AlertService::class));
    $listener->handle(telegramAlertFailedJobEvent(TelegramAlert::class));

    Notification::assertNothingSent();
});
