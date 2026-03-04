<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.logo_repository_enabled')) {
            $this->migrator->add('general.logo_repository_enabled', false);
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('general.logo_repository_enabled');
    }
};
