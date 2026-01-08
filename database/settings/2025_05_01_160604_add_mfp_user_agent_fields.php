<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.mediaflow_proxy_playlist_user_agent')) {
            $this->migrator->add('general.mediaflow_proxy_playlist_user_agent', true);
        }
        if (! $this->migrator->exists('general.mediaflow_proxy_user_agent')) {
            $this->migrator->add('general.mediaflow_proxy_user_agent', null);
        }
    }
};
