<?php

namespace App\Services;

use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Mail;

/**
 * Single owner of the shared SMTP configuration (Settings > SMTP).
 *
 * Every feature that sends mail (alerts, post-process emails, the test email
 * action) applies the transport through here instead of setting the mail
 * config keys itself.
 */
class SmtpMailService
{
    public const DEFAULT_FROM_ADDRESS = 'no-reply@m3u-editor.dev';

    public const FROM_NAME = 'm3u editor';

    public function __construct(private readonly GeneralSettings $settings) {}

    /**
     * Whether the minimum SMTP settings (host and port) are present.
     *
     * @param  array{smtp_host?: ?string, smtp_port?: int|string|null}|null  $smtp  Unsaved values (e.g. form state); null uses the saved settings.
     */
    public function isConfigured(?array $smtp = null): bool
    {
        $smtp ??= $this->savedSettings();

        return ! empty($smtp['smtp_host']) && ! empty($smtp['smtp_port']);
    }

    /**
     * Point the default mailer at the SMTP settings.
     *
     * The resolved smtp mailer is purged so a long-running worker picks up
     * settings changed since it first sent mail, instead of reusing the
     * transport it built back then.
     *
     * @param  array{smtp_host?: ?string, smtp_port?: int|string|null, smtp_username?: ?string, smtp_password?: ?string, smtp_encryption?: ?string, smtp_from_address?: ?string}|null  $smtp  Unsaved values (e.g. form state); null uses the saved settings.
     */
    public function apply(?array $smtp = null): void
    {
        $smtp ??= $this->savedSettings();

        Config::set('mail.default', 'smtp');
        Config::set('mail.from.address', ($smtp['smtp_from_address'] ?? null) ?: self::DEFAULT_FROM_ADDRESS);
        Config::set('mail.from.name', self::FROM_NAME);
        Config::set('mail.mailers.smtp.host', $smtp['smtp_host'] ?? null);
        Config::set('mail.mailers.smtp.username', $smtp['smtp_username'] ?? null);
        Config::set('mail.mailers.smtp.password', $smtp['smtp_password'] ?? null);
        Config::set('mail.mailers.smtp.port', $smtp['smtp_port'] ?? null);
        Config::set('mail.mailers.smtp.encryption', $smtp['smtp_encryption'] ?? null);

        Mail::purge('smtp');
    }

    /**
     * @return array{smtp_host: ?string, smtp_port: ?int, smtp_username: ?string, smtp_password: ?string, smtp_encryption: ?string, smtp_from_address: ?string}
     */
    private function savedSettings(): array
    {
        return [
            'smtp_host' => $this->settings->smtp_host,
            'smtp_port' => $this->settings->smtp_port,
            'smtp_username' => $this->settings->smtp_username,
            'smtp_password' => $this->settings->smtp_password,
            'smtp_encryption' => $this->settings->smtp_encryption,
            'smtp_from_address' => $this->settings->smtp_from_address,
        ];
    }
}
