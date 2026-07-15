<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.telegram_alerts_enabled')) {
            $this->migrator->add('general.telegram_alerts_enabled', false);
        }
        if (! $this->migrator->exists('general.telegram_bot_token')) {
            $this->migrator->add('general.telegram_bot_token', null);
        }
        if (! $this->migrator->exists('general.telegram_chat_id')) {
            $this->migrator->add('general.telegram_chat_id', null);
        }
    }
};
