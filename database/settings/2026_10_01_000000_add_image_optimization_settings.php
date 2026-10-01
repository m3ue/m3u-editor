<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $defaults = [
            'general.image_optimization_enabled' => true,
            'general.image_poster_width' => 600,
            'general.image_backdrop_width' => 1280,
            'general.image_title_logo_width' => 800,
            'general.image_photo_width' => 300,
            'general.image_quality' => null,
        ];

        foreach ($defaults as $key => $value) {
            if (! $this->migrator->exists($key)) {
                $this->migrator->add($key, $value);
            }
        }
    }
};
