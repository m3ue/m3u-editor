<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.default_cast_stream_profile_id')) {
            $this->migrator->add('general.default_cast_stream_profile_id', null);
        }

        if (! $this->migrator->exists('general.default_cast_vod_stream_profile_id')) {
            $this->migrator->add('general.default_cast_vod_stream_profile_id', null);
        }
    }
};
