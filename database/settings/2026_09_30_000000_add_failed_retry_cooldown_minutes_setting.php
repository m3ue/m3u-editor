<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.failed_retry_cooldown_minutes')) {
            $this->migrator->add('general.failed_retry_cooldown_minutes', 15);
        }

        // Replaced by the cooldown above plus per-playlist auto resync (dev builds only)
        $this->migrator->deleteIfExists('general.invalidate_import_retry_backoff');
    }
};
