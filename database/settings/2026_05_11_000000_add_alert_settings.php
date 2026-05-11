<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.discord_alerts_enabled')) {
            $this->migrator->add('general.discord_alerts_enabled', false);
        }
        if (! $this->migrator->exists('general.discord_webhook_url')) {
            $this->migrator->add('general.discord_webhook_url', null);
        }
        if (! $this->migrator->exists('general.slack_alerts_enabled')) {
            $this->migrator->add('general.slack_alerts_enabled', false);
        }
        if (! $this->migrator->exists('general.slack_webhook_url')) {
            $this->migrator->add('general.slack_webhook_url', null);
        }
        if (! $this->migrator->exists('general.alerts_on_job_failed')) {
            $this->migrator->add('general.alerts_on_job_failed', false);
        }
        if (! $this->migrator->exists('general.alerts_on_import_failed')) {
            $this->migrator->add('general.alerts_on_import_failed', false);
        }
    }
};
