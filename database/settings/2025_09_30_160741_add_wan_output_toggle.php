<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.output_wan_address')) {
            $this->migrator->add('general.output_wan_address', false);
        }
    }
};
