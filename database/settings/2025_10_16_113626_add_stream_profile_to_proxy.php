<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Only add if it doesn't already exist
        if (! $this->migrator->exists('general.default_stream_profile_id')) {
            $this->migrator->add('general.default_stream_profile_id', null);
        }
    }
};
