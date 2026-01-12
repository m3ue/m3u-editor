<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        // Max concurrent broadcasting networks
        if (! $this->migrator->exists('general.broadcast_max_concurrent')) {
            $this->migrator->add('general.broadcast_max_concurrent', 10);
        }
    }

    public function down(): void
    {
        $this->migrator->deleteIfExists('general.broadcast_max_concurrent');
    }
};
