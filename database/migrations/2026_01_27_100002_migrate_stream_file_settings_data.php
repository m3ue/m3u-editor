<?php

use App\Models\StreamFileSetting;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Get the admin user (or first user)
        $user = User::where('email', config('dev.admin_emails')[0])->first() ?? User::first();

        if (! $user) {
            return; // No users yet, nothing to migrate
        }

        // Read existing settings directly from the repository to avoid instantiating GeneralSettings during migrations (prevents MissingSettings in tests)
        $settingsMapper = app(\Spatie\LaravelSettings\SettingsMapper::class);
        $config = $settingsMapper->initialize(GeneralSettings::class);
        $repo = $config->getRepository();
        $existing = $repo->getPropertiesInGroup($config->getGroup());

        // Only migrate if there are existing sync settings enabled
        $seriesEnabled = $existing['stream_file_sync_enabled'] ?? false;
        $vodEnabled = $existing['vod_stream_file_sync_enabled'] ?? false;

        $updates = [];

        // Create default Series Stream File Setting from existing settings
        if ($seriesEnabled || ($existing['stream_file_sync_location'] ?? null)) {
            $seriesProfile = StreamFileSetting::create([
                'user_id' => $user->id,
                'name' => 'Default Series Settings (Migrated)',
                'description' => 'Automatically migrated from global settings',
                'type' => 'series',
                'enabled' => $seriesEnabled,
                'location' => $existing['stream_file_sync_location'] ?? null,
                'path_structure' => $existing['stream_file_sync_path_structure'] ?? ['category', 'series', 'season'],
                'filename_metadata' => $existing['stream_file_sync_filename_metadata'] ?? [],
                'tmdb_id_format' => $existing['stream_file_sync_tmdb_id_format'] ?? 'square',
                'clean_special_chars' => $existing['stream_file_sync_clean_special_chars'] ?? true,
                'remove_consecutive_chars' => $existing['stream_file_sync_remove_consecutive_chars'] ?? true,
                'replace_char' => $existing['stream_file_sync_replace_char'] ?? 'space',
                'name_filter_enabled' => $existing['stream_file_sync_name_filter_enabled'] ?? false,
                'name_filter_patterns' => $existing['stream_file_sync_name_filter_patterns'] ?? [],
                'generate_nfo' => $existing['stream_file_sync_generate_nfo'] ?? false,
            ]);

            $updates['default_series_stream_file_setting_id'] = $seriesProfile->id;
        }

        // Create default VOD Stream File Setting from existing settings
        if ($vodEnabled || ($existing['vod_stream_file_sync_location'] ?? null)) {
            $vodProfile = StreamFileSetting::create([
                'user_id' => $user->id,
                'name' => 'Default VOD Settings (Migrated)',
                'description' => 'Automatically migrated from global settings',
                'type' => 'vod',
                'enabled' => $vodEnabled,
                'location' => $existing['vod_stream_file_sync_location'] ?? null,
                'path_structure' => $existing['vod_stream_file_sync_path_structure'] ?? ['group', 'title'],
                'filename_metadata' => $existing['vod_stream_file_sync_filename_metadata'] ?? [],
                'tmdb_id_format' => $existing['vod_stream_file_sync_tmdb_id_format'] ?? 'square',
                'clean_special_chars' => $existing['vod_stream_file_sync_clean_special_chars'] ?? true,
                'remove_consecutive_chars' => $existing['vod_stream_file_sync_remove_consecutive_chars'] ?? true,
                'replace_char' => $existing['vod_stream_file_sync_replace_char'] ?? 'space',
                'name_filter_enabled' => $existing['vod_stream_file_sync_name_filter_enabled'] ?? false,
                'name_filter_patterns' => $existing['vod_stream_file_sync_name_filter_patterns'] ?? [],
                'generate_nfo' => $existing['vod_stream_file_sync_generate_nfo'] ?? false,
            ]);

            $updates['default_vod_stream_file_setting_id'] = $vodProfile->id;
        }

        if (! empty($updates)) {
            $repo->updatePropertiesPayload($config->getGroup(), $updates);
        }
    }

    public function down(): void
    {
        // Remove migrated profiles (be careful not to delete user-created ones)
        StreamFileSetting::where('name', 'like', '% (Migrated)')->delete();

        // Reset the default IDs using the repository (avoid instantiating GeneralSettings during rollback)
        $settingsMapper = app(\Spatie\LaravelSettings\SettingsMapper::class);
        $config = $settingsMapper->initialize(GeneralSettings::class);
        $repo = $config->getRepository();

        $repo->updatePropertiesPayload($config->getGroup(), [
            'default_series_stream_file_setting_id' => null,
            'default_vod_stream_file_setting_id' => null,
        ]);
    }
};
