<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\StreamProfile;
use App\Services\M3uProxyService;
use App\Settings\GeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class M3uProxyApiController extends Controller
{
    /**
     * Get the proxied URL for a channel and redirect
     * 
     * @param  Request  $request
     * @param  int  $id
     * 
     * @return \Illuminate\Http\RedirectResponse
     */
    public function channel(Request $request, $id)
    {
        $channel = Channel::query()->with(['playlist.streamProfile'])->findOrFail($id);
        $playlist = $channel->getEffectivePlaylist();

        // Get stream profile from playlist (if set)
        $profile = $playlist->streamProfile;
        $url = app(M3uProxyService::class)->getChannelUrl($playlist, $id, $request, $profile);

        return redirect($url);
    }

    /**
     * Get the proxied URL for an episode and redirect
     * 
     * @param  Request  $request
     * @param  int  $id
     * 
     * @return \Illuminate\Http\RedirectResponse
     */
    public function episode(Request $request, $id)
    {
        $episode = Episode::query()->with(['playlist.streamProfile'])->findOrFail($id);
        $playlist = $episode->playlist;

        // Get stream profile from playlist (if set)
        $profile = $playlist->streamProfile;
        $url = app(M3uProxyService::class)->getEpisodeUrl($playlist, $id, $profile);

        return redirect($url);
    }

    /**
     * Example player endpoint for channel using m3u-proxy
     * 
     * @param  Request  $request
     * @param  int  $id
     * 
     * @return StreamedResponse
     */
    public function channelPlayer(Request $request, $id)
    {
        $channel = Channel::query()->with(['playlist.streamProfile'])->findOrFail($id);
        $playlist = $channel->getEffectivePlaylist();

        // Get stream profile from playlist (if set), else fall back to default player profile
        $profile = $playlist->streamProfile;
        if (!$profile) {
            // Use default profile set for the player
            $settings = app(GeneralSettings::class);
            $profileId = $settings->default_stream_profile_id ?? null;
            $profile = $profileId ? StreamProfile::find($profileId) : null;
        }

        $url = app(M3uProxyService::class)->getChannelUrl($playlist, $id, $request, $profile);

        return redirect($url);
    }

    /**
     * Example player endpoint for episode using m3u-proxy
     * 
     * @param  Request  $request
     * @param  int  $id
     * 
     * @return StreamedResponse
     */
    public function episodePlayer(Request $request, $id)
    {
        $episode = Episode::query()->with(['playlist.streamProfile'])->findOrFail($id);
        $playlist = $episode->playlist;

        // Get stream profile from playlist (if set), else fall back to default player profile
        $profile = $playlist->streamProfile;
        if (!$profile) {
            // Use default profile set for the player
            $settings = app(GeneralSettings::class);
            $profileId = $settings->default_stream_profile_id ?? null;
            $profile = $profileId ? StreamProfile::find($profileId) : null;
        }

        $url = app(M3uProxyService::class)->getEpisodeUrl($playlist, $id, $profile);

        return redirect($url);
    }

    /**
     * Handle webhooks from m3u-proxy for real-time cache invalidation
     */
    public function handleWebhook(Request $request)
    {
        $eventType = $request->input('event_type');
        $streamId = $request->input('stream_id');
        $data = $request->input('data', []);

        Log::info('Received m3u-proxy webhook', [
            'event_type' => $eventType,
            'stream_id' => $streamId,
            'data' => $data
        ]);

        // Invalidate caches based on event type
        switch ($eventType) {
            case 'CLIENT_CONNECTED':
            case 'CLIENT_DISCONNECTED':
            case 'STREAM_STARTED':
            case 'STREAM_ENDED':
                $this->invalidateStreamCaches($data);
                break;
        }

        return response()->json(['status' => 'ok']);
    }

    protected function invalidateStreamCaches(array $data): void
    {
        // Invalidate playlist-specific cache if we have metadata
        if (isset($data['playlist_uuid'])) {
            M3uProxyService::invalidateMetadataCache('playlist_uuid', $data['playlist_uuid']);
        }

        // Invalidate channel-specific cache if we have channel metadata
        if (isset($data['type'], $data['id'])) {
            M3uProxyService::invalidateMetadataCache('type', $data['type']);
            // We might also want to invalidate specific channel caches?
        }

        Log::info('Cache invalidated for m3u-proxy event', $data);
    }
}
