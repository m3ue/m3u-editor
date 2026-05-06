<?php

namespace App\Sync\Phases\PreSync;

use App\Enums\PlaylistSourceType;
use App\Jobs\ProcessM3uImport;
use App\Jobs\SyncMediaServer;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\SyncRun;
use App\Sync\Phases\AbstractPhase;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;

/**
 * Halt the sync pipeline for media-server-backed playlists (Emby / Jellyfin)
 * and redirect to the appropriate sync path:
 *
 *   - When a {@see MediaServerIntegration} is attached, dispatch the
 *     {@see SyncMediaServer} job (the M3U import path doesn't apply).
 *   - When no integration exists, send a warning notification so the user
 *     knows to wire one up rather than silently dropping the sync.
 *
 * In both cases the M3U import is suppressed by returning a halt context.
 *
 * Mirrors the in-job guard inside {@see ProcessM3uImport::handle()};
 * that guard remains as defense-in-depth.
 */
class MediaServerRedirectPhase extends AbstractPhase
{
    public static function slug(): string
    {
        return 'media_server_redirect';
    }

    protected function execute(SyncRun $run, Playlist $playlist, array $context): ?array
    {
        if (! in_array($playlist->source_type, [PlaylistSourceType::Emby, PlaylistSourceType::Jellyfin], true)) {
            return null;
        }

        $integration = MediaServerIntegration::where('playlist_id', $playlist->id)->first();

        if ($integration) {
            Log::info('PreSync: redirecting media server playlist to SyncMediaServer', [
                'playlist_id' => $playlist->id,
                'integration_id' => $integration->id,
                'sync_run_id' => $run->id,
            ]);

            dispatch(new SyncMediaServer($integration->id));

            return [
                'halt' => true,
                'halt_reason' => 'media_server_redirected',
                'media_server_integration_id' => $integration->id,
            ];
        }

        Log::warning('PreSync: media server playlist has no integration, skipping to prevent data loss', [
            'playlist_id' => $playlist->id,
            'source_type' => $playlist->source_type?->value,
            'sync_run_id' => $run->id,
        ]);

        Notification::make()
            ->warning()
            ->title('Playlist sync skipped')
            ->body("Playlist \"{$playlist->name}\" is a media server playlist but no integration was found. Please sync from the Media Server Integrations page.")
            ->broadcast($playlist->user)
            ->sendToDatabase($playlist->user);

        return [
            'halt' => true,
            'halt_reason' => 'media_server_no_integration',
        ];
    }
}
