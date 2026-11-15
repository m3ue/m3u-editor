<?php

use App\Jobs\SendAlertEmail;
use App\Listeners\AlertOnJobFailed;
use App\Mail\AlertMail;
use App\Services\AlertService;
use App\Services\SmtpMailService;
use App\Settings\GeneralSettings;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;

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
        'email_alerts_enabled' => false,
        'email_alerts_recipients' => null,
        'alerts_on_job_failed' => true,
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

    app()->instance(GeneralSettings::class, $settings);

    return $settings;
}

function emailAlertConfiguredSettings(array $overrides = []): GeneralSettings
{
    return emailAlertMockSettings(array_merge([
        'email_alerts_enabled' => true,
        'email_alerts_recipients' => ['owner@example.com', 'ops@example.com'],
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 587,
        'smtp_username' => 'mailer',
        'smtp_password' => 'secret',
        'smtp_encryption' => 'tls',
        'smtp_from_address' => 'alerts@example.com',
    ], $overrides));
}

it('queues an alert email to every configured recipient', function () {
    Bus::fake([SendAlertEmail::class]);
    emailAlertConfiguredSettings();

    $service = app(AlertService::class);

    expect($service->isEnabled())->toBeTrue();

    $service->send('Something went wrong');

    Bus::assertDispatched(SendAlertEmail::class, fn (SendAlertEmail $job): bool => $job->recipients === ['owner@example.com', 'ops@example.com']
        && $job->emailSubject === AlertService::EMAIL_SUBJECT
        && $job->body === 'Something went wrong');
});

it('does not send email alerts unless enabled with recipients and smtp', function (array $overrides) {
    Bus::fake([SendAlertEmail::class]);
    emailAlertConfiguredSettings($overrides);

    $service = app(AlertService::class);
    $service->send('Something went wrong');

    expect($service->isEnabled())->toBeFalse();
    Bus::assertNotDispatched(SendAlertEmail::class);
})->with([
    'toggle off' => [['email_alerts_enabled' => false]],
    'no recipients' => [['email_alerts_recipients' => null]],
    'blank recipients' => [['email_alerts_recipients' => ['', '  ']]],
    'missing smtp host' => [['smtp_host' => null]],
    'missing smtp port' => [['smtp_port' => null]],
]);

it('sends the alert email through the saved smtp settings when the job runs', function () {
    Mail::fake();
    emailAlertConfiguredSettings();

    (new SendAlertEmail(['owner@example.com'], 'Alert subject', 'Alert body'))->handle(app(SmtpMailService::class));

    Mail::assertSent(AlertMail::class, fn (AlertMail $mail): bool => $mail->hasTo('owner@example.com')
        && $mail->emailSubject === 'Alert subject'
        && $mail->body === 'Alert body');

    expect(config('mail.default'))->toBe('smtp')
        ->and(config('mail.from.address'))->toBe('alerts@example.com')
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp.example.com')
        ->and(config('mail.mailers.smtp.port'))->toBe(587);
});

it('skips sending when smtp was cleared after the job was queued', function () {
    Mail::fake();
    emailAlertConfiguredSettings(['smtp_host' => null]);

    (new SendAlertEmail(['owner@example.com'], 'Alert subject', 'Alert body'))->handle(app(SmtpMailService::class));

    Mail::assertNothingSent();
});

it('renders the alert body as plain text without escaping or markdown', function () {
    $body = "[JOB FAILED] App\\Jobs\\Some_Job: \"boom\" <tag>\n```\n{\"queue\": \"default\"}\n```";

    $rendered = (new AlertMail('Subject', $body))->render();

    expect(trim($rendered))->toBe($body);
});

it('delivers the alert as a text-only message with the body unchanged', function () {
    $body = "[ERROR] Sync failed for \"News & Sports\" <tag>\n```\n{\"queue\": \"default\"}\n```";

    Mail::mailer('array')->to('owner@example.com')->send(new AlertMail('Subject', $body));

    $email = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();

    expect($email->getTextBody())->toBe($body)
        ->and($email->getHtmlBody())->toBeNull();
});

it('rebuilds the smtp mailer so changed settings take effect in a long-running worker', function () {
    emailAlertConfiguredSettings();
    $smtp = app(SmtpMailService::class);

    $smtp->apply();
    $first = Mail::mailer('smtp');

    $smtp->apply(['smtp_host' => 'smtp2.example.com', 'smtp_port' => 2525]);
    $second = Mail::mailer('smtp');

    expect($second)->not->toBe($first)
        ->and(config('mail.mailers.smtp.host'))->toBe('smtp2.example.com')
        ->and(config('mail.from.address'))->toBe(SmtpMailService::DEFAULT_FROM_ADDRESS);
});

it('does not alert about a failed email alert job', function () {
    Bus::fake([SendAlertEmail::class]);
    emailAlertConfiguredSettings();

    $job = Mockery::mock(Job::class);
    $job->shouldReceive('resolveName')->andReturn(SendAlertEmail::class);
    $job->shouldReceive('getQueue')->andReturn('default');

    app(AlertOnJobFailed::class)->handle(new JobFailed('redis', $job, new Exception('smtp down')));

    Bus::assertNotDispatched(SendAlertEmail::class);
});
