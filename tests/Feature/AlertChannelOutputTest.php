<?php

/**
 * Output contract for every alert channel: the message handed to
 * AlertService::send() must reach Discord, Slack, Telegram and email intact.
 * Alert bodies are forwarded log lines and job failures, so the fixture mixes
 * the characters most likely to be mangled (markdown, HTML, code fences,
 * ampersands, quotes and blank lines).
 */

use App\Jobs\SendAlertEmail;
use App\Mail\AlertMail;
use App\Notifications\TelegramAlert;
use App\Services\AlertService;
use App\Services\SmtpMailService;
use App\Settings\GeneralSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    // config/queue.php pins the default connection to redis, so without this
    // the alert jobs would be pushed to the real Redis queue instead of
    // running inline where the faked HTTP client can record them.
    config(['queue.default' => 'sync']);
    Http::preventStrayRequests();
});

const ALERT_OUTPUT_DISCORD_WEBHOOK = 'https://discord.com/api/webhooks/123/abc';
const ALERT_OUTPUT_SLACK_WEBHOOK = 'https://hooks.slack.com/services/T000/B000/XXXX';

function alertOutputMessage(): string
{
    return "[JOB FAILED] App\\Jobs\\Process_M3u_Import on \"News & Sports\" <b>not bold</b> *not bold*\n"
        ."\n"
        ."```\n"
        ."{\"queue\": \"default\", \"playlist_id\": 42}\n"
        .'```';
}

/**
 * The alert body as a mail client shows it: the decoded contents of the
 * html part's message block.
 */
function alertOutputHtmlBlockText(string $html): ?string
{
    if (! preg_match('/<pre class="message-block"[^>]*>(.*?)<\/pre>/s', $html, $matches)) {
        return null;
    }

    return html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5);
}

function alertOutputSettings(array $overrides = []): GeneralSettings
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

it('posts the alert to the discord webhook with the message as content', function () {
    Http::fake();
    alertOutputSettings([
        'discord_alerts_enabled' => true,
        'discord_webhook_url' => ALERT_OUTPUT_DISCORD_WEBHOOK,
    ]);

    app(AlertService::class)->send(alertOutputMessage());

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === ALERT_OUTPUT_DISCORD_WEBHOOK
        && $request->method() === 'POST'
        && $request['content'] === alertOutputMessage()
        && $request['tts'] === false
        && ! isset($request['embeds']));
});

it('posts the alert to the slack webhook with the message as text', function () {
    Http::fake();
    alertOutputSettings([
        'slack_alerts_enabled' => true,
        'slack_webhook_url' => ALERT_OUTPUT_SLACK_WEBHOOK,
    ]);

    app(AlertService::class)->send(alertOutputMessage());

    Http::assertSentCount(1);
    Http::assertSent(fn (Request $request): bool => $request->url() === ALERT_OUTPUT_SLACK_WEBHOOK
        && $request->method() === 'POST'
        && $request['text'] === alertOutputMessage()
        && $request['type'] === 'mrkdwn'
        && ! isset($request['blocks']));
});

it('sends the alert to telegram as plain text with the message unchanged', function () {
    Notification::fake();
    alertOutputSettings([
        'telegram_alerts_enabled' => true,
        'telegram_bot_token' => Crypt::encryptString('test-token'),
        'telegram_chat_id' => '123456789',
    ]);

    app(AlertService::class)->send(alertOutputMessage());

    Notification::assertSentOnDemand(TelegramAlert::class, function (TelegramAlert $notification, array $channels, AnonymousNotifiable $notifiable): bool {
        $payload = $notification->toTelegram($notifiable)->toArray();

        return $payload['text'] === alertOutputMessage()
            && ! array_key_exists('parse_mode', $payload);
    });
});

it('delivers the alert email with a verbatim text part and an escaped html part', function () {
    alertOutputSettings([
        'email_alerts_enabled' => true,
        'email_alerts_recipients' => ['owner@example.com'],
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 587,
    ]);

    // The job applies the SMTP settings in the worker; capture what it would
    // send through the array transport instead of a real SMTP server.
    $smtp = Mockery::mock(SmtpMailService::class);
    $smtp->shouldReceive('isConfigured')->andReturnTrue();
    $smtp->shouldReceive('apply')->andReturnUsing(fn () => config(['mail.default' => 'array']));
    app()->instance(SmtpMailService::class, $smtp);

    app(AlertService::class)->send(alertOutputMessage());

    $email = Mail::mailer('array')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
    $html = $email->getHtmlBody();

    expect($email->getSubject())->toBe(AlertService::EMAIL_SUBJECT)
        ->and($email->getTextBody())->toBe(alertOutputMessage())
        ->and($html)->toContain('M3U Editor')
        ->and(alertOutputHtmlBlockText($html))->toBe(alertOutputMessage())
        ->and($html)->not->toContain('<b>not bold</b>')
        ->and($html)->not->toContain('<em>')
        ->and($html)->not->toContain('<strong>not bold</strong>')
        ->and($html)->not->toContain('<code>');
});

it('fans one alert out to every enabled channel', function () {
    Http::fake();
    Notification::fake();
    Bus::fake([SendAlertEmail::class]);
    alertOutputSettings([
        'discord_alerts_enabled' => true,
        'discord_webhook_url' => ALERT_OUTPUT_DISCORD_WEBHOOK,
        'slack_alerts_enabled' => true,
        'slack_webhook_url' => ALERT_OUTPUT_SLACK_WEBHOOK,
        'telegram_alerts_enabled' => true,
        'telegram_bot_token' => Crypt::encryptString('test-token'),
        'telegram_chat_id' => '123456789',
        'email_alerts_enabled' => true,
        'email_alerts_recipients' => ['owner@example.com'],
        'smtp_host' => 'smtp.example.com',
        'smtp_port' => 587,
    ]);

    app(AlertService::class)->send('Playlist sync failed');

    Http::assertSent(fn (Request $request): bool => $request->url() === ALERT_OUTPUT_DISCORD_WEBHOOK && $request['content'] === 'Playlist sync failed');
    Http::assertSent(fn (Request $request): bool => $request->url() === ALERT_OUTPUT_SLACK_WEBHOOK && $request['text'] === 'Playlist sync failed');
    Notification::assertSentOnDemandTimes(TelegramAlert::class, 1);
    Bus::assertDispatched(SendAlertEmail::class, fn (SendAlertEmail $job): bool => $job->body === 'Playlist sync failed');
});

it('keeps sending to the other channels when one webhook fails', function () {
    Http::fake([
        ALERT_OUTPUT_SLACK_WEBHOOK => Http::response('invalid_token', 403),
        '*' => Http::response(),
    ]);
    alertOutputSettings([
        'slack_alerts_enabled' => true,
        'slack_webhook_url' => ALERT_OUTPUT_SLACK_WEBHOOK,
        'discord_alerts_enabled' => true,
        'discord_webhook_url' => ALERT_OUTPUT_DISCORD_WEBHOOK,
    ]);

    app(AlertService::class)->send('Playlist sync failed');

    Http::assertSent(fn (Request $request): bool => $request->url() === ALERT_OUTPUT_DISCORD_WEBHOOK);
    Http::assertSent(fn (Request $request): bool => $request->url() === ALERT_OUTPUT_SLACK_WEBHOOK);
});

it('renders the alert email html inside the branded layout', function () {
    $html = (new AlertMail('Subject', 'Disk usage at 95%'))->render();

    expect($html)->toContain('M3U Editor')
        ->and($html)->toContain('M3U Editor')
        ->and($html)->toContain('class="message-block"')
        ->and($html)->toContain('Disk usage at 95%')
        ->and($html)->toContain(config('dev.docs_url'));
});
