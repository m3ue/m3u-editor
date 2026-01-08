<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Add Emby groups and categories import settings
 *
 * These settings control automatic creation of groups for VODs and categories
 * for series based on Emby genre metadata during sync operations.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        // Enable/disable automatic groups and categories import from Emby
        if (! $this->migrator->exists('general.emby_import_groups_categories')) {
            $this->migrator->add('general.emby_import_groups_categories', false);
        }

        // Configure how to handle content with multiple genres
        // 'primary' = use first genre only, 'all' = create in all genres
        if (! $this->migrator->exists('general.emby_genre_handling')) {
            $this->migrator->add('general.emby_genre_handling', 'primary');
        }
    }
};
