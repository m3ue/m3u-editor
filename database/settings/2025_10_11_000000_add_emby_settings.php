<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Add media server integration settings for Emby/Jellyfin compatibility
 *
 * These settings support both Emby and Jellyfin media servers as they
 * share the same API structure and authentication methods.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        // Media server URL - compatible with both Emby and Jellyfin
        if (!$this->migrator->exists('general.emby_server_url')) {
            $this->migrator->add('general.emby_server_url', null);
        }
        // API key for authentication - works with both platforms
        if (!$this->migrator->exists('general.emby_api_key')) {
            $this->migrator->add('general.emby_api_key', null);
        }
    }
};