<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.show_queue_indicator')) {
            $this->migrator->add('general.show_queue_indicator', true);
        }
    }
};
