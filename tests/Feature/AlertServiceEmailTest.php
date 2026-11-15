<?php

use App\Mail\AlertMail;
use App\Services\AlertService;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Mail::fake();
});

function emailAlertMockSettings(array $overrides = []): GeneralSettings
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
        'smtp_host' => null,
        'smtp_port' => null,
        'smtp_username' => null,
        'smtp_password' => null,
        'smtp_encryption' => null,
        'smtp_from_address' => null,
    ];

    foreach (array_merge($defaults, $overrides) as $key => $value) {
        $settings->{$key} = $value;
    }

    return $settings;
}

it('sends an alert email through the configured smtp mailer', function () {
    $settings = emailAlertMockSettings([
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 587,
        'smtp_username' => 'mailer',
        'smtp_password' => 'secret',
        'smtp_encryption' => 'tls',
        'smtp_from_address' => 'alerts@example.com',
    ]);

    (new AlertService($settings))->send(
        message: 'Provider subscription expires in three days.',
        email: 'owner@example.com',
        subject: 'Subscription expiring soon',
    );

    Mail::assertSent(AlertMail::class, function (AlertMail $mail): bool {
        return $mail->hasTo('owner@example.com')
            && $mail->emailSubject === 'Subscription expiring soon'
            && $mail->body === 'Provider subscription expires in three days.';
    });

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.from.address'))->toBe('alerts@example.com')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.com')
        ->and(config('mail.mailers.smtp.port'))->toBe(587);
});

it('does not send email without a recipient', function () {
    $settings = emailAlertMockSettings([
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 587,
    ]);

    (new AlertService($settings))->send('Something went wrong');

    Mail::assertNothingSent();
});

it('does not send email when smtp is incomplete', function (array $overrides) {
    $settings = emailAlertMockSettings($overrides);

    (new AlertService($settings))->send('Something went wrong', 'owner@example.com');

    Mail::assertNothingSent();
})->with([
    'missing host' => [['smtp_port' => 587]],
    'missing port' => [['smtp_host' => 'smtp.example.com']],
]);

it('reports email as enabled only when smtp and a recipient are available', function () {
    $settings = emailAlertMockSettings([
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 587,
    ]);
    $service = new AlertService($settings);

    expect($service->isEnabled('owner@example.com'))->toBeTrue()
        ->and($service->isEnabled())->toBeFalse();
});
