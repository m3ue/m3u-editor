<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        if (! $this->migrator->exists('general.tmdb_min_vote_count')) {
            $this->migrator->add('general.tmdb_min_vote_count', 25);
        }
    }
};
