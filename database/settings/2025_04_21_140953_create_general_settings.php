<?php

use Filament\Support\Enums\MaxWidth;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (!$this->migrator->exists('general.navigation_position')) {
            $this->migrator->add('general.navigation_position', 'left');
        }
        if (!$this->migrator->exists('general.show_breadcrumbs')) {
            $this->migrator->add('general.show_breadcrumbs', true);
        }
        if (!$this->migrator->exists('general.show_logs')) {
            $this->migrator->add('general.show_logs', false);
        }
        if (!$this->migrator->exists('general.show_api_docs')) {
            $this->migrator->add('general.show_api_docs', false);
        }
        if (!$this->migrator->exists('general.show_queue_manager')) {
            $this->migrator->add('general.show_queue_manager', false);
        }
        if (!$this->migrator->exists('general.ffmpeg_user_agent')) {
            $this->migrator->add('general.ffmpeg_user_agent', 'VLC/3.0.21 LibVLC/3.0.21');
        }
        if (!$this->migrator->exists('general.ffmpeg_debug')) {
            $this->migrator->add('general.ffmpeg_debug', false);
        }
        if (!$this->migrator->exists('general.ffmpeg_max_tries')) {
            $this->migrator->add('general.ffmpeg_max_tries', 3);
        }
        if (!$this->migrator->exists('general.mediaflow_proxy_url')) {
            $this->migrator->add('general.mediaflow_proxy_url', null);
        }
        if (!$this->migrator->exists('general.mediaflow_proxy_port')) {
            $this->migrator->add('general.mediaflow_proxy_port', null);
        }
        if (!$this->migrator->exists('general.mediaflow_proxy_password')) {
            $this->migrator->add('general.mediaflow_proxy_password', null);
        }
        if (!$this->migrator->exists('general.content_width')) {
            $this->migrator->add('general.content_width', MaxWidth::ScreenExtraLarge->value);
        }
    }
};
