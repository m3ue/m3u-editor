<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.mediaflow_proxy_rewrite_stream_urls')) {
            $this->migrator->add('general.mediaflow_proxy_rewrite_stream_urls', false);
        }
    }
};
