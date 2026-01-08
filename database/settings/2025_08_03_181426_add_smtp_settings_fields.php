<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.smtp_host')) {
            $this->migrator->add('general.smtp_host', config('mail.host') ?? 'smtp.mailtrap.io');
        }
        if (! $this->migrator->exists('general.smtp_port')) {
            $this->migrator->add('general.smtp_port', config('mail.port') ?? 2525);
        }
        if (! $this->migrator->exists('general.smtp_username')) {
            $this->migrator->add('general.smtp_username', config('mail.username') ?? '');
        }
        if (! $this->migrator->exists('general.smtp_password')) {
            $this->migrator->add('general.smtp_password', config('mail.password') ?? '');
        }
        if (! $this->migrator->exists('general.smtp_encryption')) {
            $this->migrator->add('general.smtp_encryption', config('mail.encryption') ?? null);
        }
        if (! $this->migrator->exists('general.smtp_from_address')) {
            $this->migrator->add('general.smtp_from_address', config('mail.from.address') ?? '');
        }
    }
};
