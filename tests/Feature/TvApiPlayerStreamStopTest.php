<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TvApiPlayerStreamStopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
    }

    public function test_owner_can_stop_a_live_channel_belonging_to_their_playlist()
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create();
        $channel = Channel::factory()->create([
            'playlist_id' => $playlist->id,
            'user_id' => $user->id,
        ]);

        $response = $this->postJson(
            "/api/tv/{$user->name}/{$playlist->uuid}/player-stream/stop",
            ['type' => 'live', 'stream_id' => $channel->id, 'client_id' => 'client-1']
        );

        $response->assertNoContent();
        Http::assertSentCount(1);
    }

    public function test_owner_cannot_stop_a_channel_belonging_to_another_playlist()
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create();

        $otherUser = User::factory()->create();
        $otherPlaylist = Playlist::factory()->for($otherUser)->create();
        $foreignChannel = Channel::factory()->create([
            'playlist_id' => $otherPlaylist->id,
            'user_id' => $otherUser->id,
        ]);

        $response = $this->postJson(
            "/api/tv/{$user->name}/{$playlist->uuid}/player-stream/stop",
            ['type' => 'live', 'stream_id' => $foreignChannel->id, 'client_id' => 'client-1']
        );

        // Same 204 whether denied or actually stopped, but the proxy is never called.
        $response->assertNoContent();
        Http::assertNothingSent();
    }

    public function test_series_type_uses_episode_id_and_checks_playlist_ownership()
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create();
        $episode = Episode::factory()->create([
            'playlist_id' => $playlist->id,
            'user_id' => $user->id,
        ]);

        $response = $this->postJson(
            "/api/tv/{$user->name}/{$playlist->uuid}/player-stream/stop",
            ['type' => 'series', 'episode_id' => $episode->id, 'client_id' => 'client-1']
        );

        $response->assertNoContent();
        Http::assertSentCount(1);
    }

    public function test_invalid_credentials_are_rejected()
    {
        $user = User::factory()->create();
        Playlist::factory()->for($user)->create();

        $response = $this->postJson(
            "/api/tv/{$user->name}/not-a-real-password/player-stream/stop",
            ['type' => 'live', 'stream_id' => 1, 'client_id' => 'client-1']
        );

        $response->assertStatus(401);
        Http::assertNothingSent();
    }

    public function test_invalid_client_id_is_rejected_by_validation()
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create();
        $channel = Channel::factory()->create([
            'playlist_id' => $playlist->id,
            'user_id' => $user->id,
        ]);

        $response = $this->postJson(
            "/api/tv/{$user->name}/{$playlist->uuid}/player-stream/stop",
            ['type' => 'live', 'stream_id' => $channel->id, 'client_id' => 'has spaces/slash']
        );

        $response->assertStatus(422);
        Http::assertNothingSent();
    }
}
