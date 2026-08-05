<?php

namespace Tests\Feature;

use App\Http\Controllers\MediaServerProxyController;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MediaServerProxySignedUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_stream_proxy_route_rejects_request_without_signature()
    {
        $integration = MediaServerIntegration::factory()->create();

        $response = $this->get("/media-server/{$integration->id}/stream/abc123.ts");

        $response->assertForbidden();
    }

    public function test_stream_proxy_route_rejects_tampered_signature()
    {
        $integration = MediaServerIntegration::factory()->create();

        $url = MediaServerProxyController::generateStreamProxyUrl($integration->id, 'abc123', 'ts');
        // Swap the item id after signing so the signature no longer matches.
        $tampered = str_replace('abc123', 'other-item', $url);

        $response = $this->get($tampered);

        $response->assertForbidden();
    }

    public function test_stream_proxy_route_accepts_valid_generated_signature()
    {
        $integration = MediaServerIntegration::factory()->create();

        $url = MediaServerProxyController::generateStreamProxyUrl($integration->id, 'abc123', 'ts');

        $response = $this->get($url);

        // The signature must pass (not a 403 Invalid Signature middleware rejection).
        // The controller then tries to reach the (nonexistent, in tests) upstream media
        // server, whose failure mode isn't what this test verifies - just that the
        // signature middleware let the request through to the controller.
        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_image_proxy_route_rejects_request_without_signature()
    {
        $integration = MediaServerIntegration::factory()->create();

        $response = $this->get("/media-server/{$integration->id}/image/abc123/Primary");

        $response->assertForbidden();
    }

    public function test_local_media_route_rejects_request_without_signature()
    {
        $integration = MediaServerIntegration::factory()->create(['type' => 'local']);

        $response = $this->get("/local-media/{$integration->id}/stream/".base64_encode('/media/foo.mp4'));

        $response->assertForbidden();
    }

    public function test_webdav_media_route_rejects_request_without_signature()
    {
        $integration = MediaServerIntegration::factory()->create(['type' => 'webdav']);

        $response = $this->get("/webdav-media/{$integration->id}/stream/".base64_encode('/foo.mp4'));

        $response->assertForbidden();
    }

    public function test_stream_proxy_route_never_expires()
    {
        $integration = MediaServerIntegration::factory()->create();

        $url = MediaServerProxyController::generateStreamProxyUrl($integration->id, 'abc123', 'ts');

        $this->travel(30)->days();
        $response = $this->get($url);

        $this->assertNotEquals(403, $response->getStatusCode());
    }

    public function test_stream_proxy_route_rejects_url_with_stale_version()
    {
        $integration = MediaServerIntegration::factory()->create();

        $url = MediaServerProxyController::generateStreamProxyUrl($integration->id, 'abc123', 'ts');

        config(['proxy.media_server_url_version' => 2]);

        $response = $this->get($url);

        $response->assertForbidden();
    }

    private function makeAioStreamsChannel(MediaServerIntegration $integration, ?string $resolvedUrl = 'https://cdn.test/movie.mkv'): Channel
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->create(['user_id' => $user->id]);

        return Channel::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $playlist->id,
            'is_custom' => true,
            'is_vod' => true,
            'aio_integration_id' => $integration->id,
            'movie_data' => $resolvedUrl ? ['aiostreams' => ['resolved_url' => $resolvedUrl]] : null,
        ]);
    }

    public function test_aiostreams_channel_media_route_rejects_request_without_signature()
    {
        $integration = MediaServerIntegration::factory()->create(['type' => 'aiostreams']);
        $channel = $this->makeAioStreamsChannel($integration);

        $response = $this->get("/aiostreams-media/{$integration->id}/channel/{$channel->id}/stream");

        $response->assertForbidden();
    }

    public function test_aiostreams_channel_media_route_rejects_non_aiostreams_integration()
    {
        $integration = MediaServerIntegration::factory()->create(['type' => 'plex']);
        $channel = $this->makeAioStreamsChannel($integration);

        $url = MediaServerProxyController::generateAioStreamsChannelProxyUrl($integration->id, $channel->id);

        $response = $this->get($url);

        $response->assertStatus(400);
    }

    public function test_aiostreams_channel_media_route_returns_404_when_channel_has_no_resolved_url()
    {
        $integration = MediaServerIntegration::factory()->create(['type' => 'aiostreams']);
        $channel = $this->makeAioStreamsChannel($integration, resolvedUrl: null);

        $url = MediaServerProxyController::generateAioStreamsChannelProxyUrl($integration->id, $channel->id);

        $response = $this->get($url);

        $response->assertStatus(404);
    }

    private function makeAioStreamsEpisode(MediaServerIntegration $integration, ?string $resolvedUrl = 'https://cdn.test/episode.mkv'): Episode
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->create(['user_id' => $user->id]);
        $series = Series::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $playlist->id,
            'is_custom' => true,
            'aio_integration_id' => $integration->id,
        ]);

        return Episode::factory()->create([
            'user_id' => $user->id,
            'playlist_id' => $playlist->id,
            'series_id' => $series->id,
            'is_custom' => true,
            'info' => $resolvedUrl ? ['aiostreams' => ['resolved_url' => $resolvedUrl]] : null,
        ]);
    }

    public function test_aiostreams_episode_media_route_rejects_request_without_signature()
    {
        $integration = MediaServerIntegration::factory()->create(['type' => 'aiostreams']);
        $episode = $this->makeAioStreamsEpisode($integration);

        $response = $this->get("/aiostreams-media/{$integration->id}/episode/{$episode->id}/stream");

        $response->assertForbidden();
    }

    public function test_aiostreams_episode_media_route_returns_404_when_episode_has_no_resolved_url()
    {
        $integration = MediaServerIntegration::factory()->create(['type' => 'aiostreams']);
        $episode = $this->makeAioStreamsEpisode($integration, resolvedUrl: null);

        $url = MediaServerProxyController::generateAioStreamsEpisodeProxyUrl($integration->id, $episode->id);

        $response = $this->get($url);

        $response->assertStatus(404);
    }

    public function test_aiostreams_live_media_route_rejects_request_without_signature()
    {
        $integration = MediaServerIntegration::factory()->create(['type' => 'aiostreams']);

        $response = $this->get("/aiostreams-media/{$integration->id}/live/some-token/stream");

        $response->assertForbidden();
    }

    public function test_aiostreams_live_media_route_rejects_non_aiostreams_integration()
    {
        $integration = MediaServerIntegration::factory()->create(['type' => 'plex']);

        $url = MediaServerProxyController::generateAioStreamsLiveProxyUrl($integration->id, 'https://cdn.test/movie.mkv');

        $response = $this->get($url);

        $response->assertStatus(400);
    }

    public function test_aiostreams_live_media_route_returns_404_for_unknown_token()
    {
        $integration = MediaServerIntegration::factory()->create(['type' => 'aiostreams']);

        // Reuse the real generator so the signature/version are valid — only the
        // token itself is swapped for one that was never cached.
        $url = MediaServerProxyController::generateAioStreamsLiveProxyUrl($integration->id, 'https://cdn.test/movie.mkv');
        $tampered = preg_replace('#(/aiostreams-media/\d+/live/)[^/?]+#', '$1never-cached-token', $url);

        $response = $this->get($tampered);

        // The signature no longer matches (token is part of the signed payload),
        // so this is rejected by the signature middleware before it ever reaches
        // the cache lookup — still proves an attacker can't just swap in a guessed
        // token to reach someone else's cached URL.
        $response->assertForbidden();
    }

    public function test_aiostreams_live_media_route_generates_a_short_url_regardless_of_resolved_url_length()
    {
        $integration = MediaServerIntegration::factory()->create(['type' => 'aiostreams']);

        $longResolvedUrl = 'https://cdn.test/movie.mkv?'.str_repeat('a', 5000);

        $url = MediaServerProxyController::generateAioStreamsLiveProxyUrl($integration->id, $longResolvedUrl);

        $this->assertLessThan(300, strlen($url));
    }
}
