<?php

use Spatie\LaravelSettings\Migrations\SettingsBlueprint;
use Spatie\LaravelSettings\Migrations\SettingsMigration;

return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->inGroup('general', function (SettingsBlueprint $blueprint): void {
            $blueprint->add('hardware_acceleration_method', 'none');
            $blueprint->add('ffmpeg_vaapi_device', null);
            $blueprint->add('ffmpeg_vaapi_video_filter', null);
            $blueprint->add('ffmpeg_qsv_device', null);
            $blueprint->add('ffmpeg_qsv_video_filter', null);
            $blueprint->add('ffmpeg_qsv_encoder_options', null);
            $blueprint->add('ffmpeg_qsv_additional_args', null);
        });
    }

    public function down(): void
    {
        $this->migrator->inGroup('general', function (SettingsBlueprint $blueprint): void {
            $blueprint->delete('hardware_acceleration_method');
            $blueprint->delete('ffmpeg_vaapi_device');
            $blueprint->delete('ffmpeg_vaapi_video_filter');
            $blueprint->delete('ffmpeg_qsv_device');
            $blueprint->delete('ffmpeg_qsv_video_filter');
            $blueprint->delete('ffmpeg_qsv_encoder_options');
            $blueprint->delete('ffmpeg_qsv_additional_args');
        });
    }
};
