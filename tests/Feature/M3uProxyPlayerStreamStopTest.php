<?php

namespace Tests\Feature;

use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class M3uProxyPlayerStreamStopTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Prevent any real network call to the m3u-proxy service; let each test
        // assert whether a stop request was (or wasn't) actually sent.
        Http::fake();
    }

    public function test_unauthenticated_caller_gets_no_content_and_does_not_stop_stream()
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create();
        $channel = Channel::factory()->create([
            'playlist_id' => $playlist->id,
            'user_id' => $user->id,
        ]);

        $response = $this->postJson('/api/m3u-proxy/player-stream/stop', [
            'id' => $channel->id,
            'type' => 'channel',
        ]);

        $response->assertNoContent();
        Http::assertNothingSent();
    }

    public function test_authenticated_owner_can_stop_own_channel_stream()
    {
        $user = User::factory()->create();
        $playlist = Playlist::factory()->for($user)->create();
        $channel = Channel::factory()->create([
            'playlist_id' => $playlist->id,
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->postJson('/api/m3u-proxy/player-stream/stop', [
            'id' => $channel->id,
            'type' => 'channel',
        ]);

        $response->assertNoContent();
        Http::assertSentCount(1);
    }

    public function test_authenticated_user_cannot_stop_another_users_channel_stream()
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $playlist = Playlist::factory()->for($owner)->create();
        $channel = Channel::factory()->create([
            'playlist_id' => $playlist->id,
            'user_id' => $owner->id,
        ]);

        $response = $this->actingAs($otherUser)->postJson('/api/m3u-proxy/player-stream/stop', [
            'id' => $channel->id,
            'type' => 'channel',
        ]);

        // Same 204 response as the owner case (so the caller can't use the
        // response to probe ownership), but the proxy must never be called.
        $response->assertNoContent();
        Http::assertNothingSent();
    }

    public function test_missing_type_or_id_still_returns_no_content()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/m3u-proxy/player-stream/stop', []);

        $response->assertNoContent();
        Http::assertNothingSent();
    }
}
