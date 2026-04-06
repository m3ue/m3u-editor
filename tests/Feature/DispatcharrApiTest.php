<?php

use App\Http\Middleware\DispatcharrAuthMiddleware;
use App\Models\Channel;
use App\Models\Group;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->createQuietly();
    $this->username = 'dispatcharr_test_'.fake()->word();
    $this->password = fake()->password();

    $playlistAuth = PlaylistAuth::create([
        'name' => 'Dispatcharr Test Auth',
        'username' => $this->username,
        'password' => $this->password,
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($playlistAuth);
});

// ──────────────────────────────────────────────────────────────────────────────
// POST /api/accounts/token/ — Login
// ──────────────────────────────────────────────────────────────────────────────

it('returns access and refresh tokens with valid credentials', function () {
    $response = $this->postJson('/api/accounts/token/', [
        'username' => $this->username,
        'password' => $this->password,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['access', 'refresh']);

    // Verify access token is valid
    $payload = DispatcharrAuthMiddleware::verifyToken($response->json('access'));
    expect($payload)->not->toBeNull()
        ->and($payload['playlist_id'])->toBe($this->playlist->id)
        ->and($payload['user_id'])->toBe($this->user->id);
});

it('rejects invalid credentials', function () {
    $response = $this->postJson('/api/accounts/token/', [
        'username' => 'wrong',
        'password' => 'wrong',
    ]);

    $response->assertStatus(401)
        ->assertJsonPath('detail', 'No active account found with the given credentials');
});

it('validates required fields on login', function () {
    $this->postJson('/api/accounts/token/', [])
        ->assertStatus(422);
});

// ──────────────────────────────────────────────────────────────────────────────
// POST /api/accounts/token/refresh/ — Token Refresh
// ──────────────────────────────────────────────────────────────────────────────

it('refreshes an access token from a valid refresh token', function () {
    $loginResponse = $this->postJson('/api/accounts/token/', [
        'username' => $this->username,
        'password' => $this->password,
    ]);

    $refreshToken = $loginResponse->json('refresh');

    $response = $this->postJson('/api/accounts/token/refresh/', [
        'refresh' => $refreshToken,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['access', 'refresh']);

    // Verify new access token is valid
    $payload = DispatcharrAuthMiddleware::verifyToken($response->json('access'));
    expect($payload)->not->toBeNull()
        ->and($payload['playlist_id'])->toBe($this->playlist->id);
});

it('rejects an invalid refresh token', function () {
    $this->postJson('/api/accounts/token/refresh/', [
        'refresh' => 'garbage.token',
    ])->assertStatus(401);
});

it('rejects an access token used as refresh', function () {
    $loginResponse = $this->postJson('/api/accounts/token/', [
        'username' => $this->username,
        'password' => $this->password,
    ]);

    $this->postJson('/api/accounts/token/refresh/', [
        'refresh' => $loginResponse->json('access'),
    ])->assertStatus(401);
});

// ──────────────────────────────────────────────────────────────────────────────
// GET /api/channels/profiles/ — Profiles
// ──────────────────────────────────────────────────────────────────────────────

it('returns groups as profiles with channel IDs', function () {
    $group1 = Group::factory()->for($this->playlist)->for($this->user)->create(['name' => 'News', 'sort_order' => 1]);
    $group2 = Group::factory()->for($this->playlist)->for($this->user)->create(['name' => 'Sports', 'sort_order' => 2]);

    $ch1 = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group1->id,
        'enabled' => true,
        'is_vod' => false,
    ]);
    $ch2 = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group1->id,
        'enabled' => true,
        'is_vod' => false,
    ]);
    $ch3 = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group2->id,
        'enabled' => true,
        'is_vod' => false,
    ]);

    $accessToken = getAccessToken();

    $response = $this->getJson('/api/channels/profiles/', [
        'Authorization' => "Bearer {$accessToken}",
    ]);

    $response->assertOk()
        ->assertJsonCount(2);

    $profiles = $response->json();
    expect($profiles[0]['name'])->toBe('News')
        ->and($profiles[0]['channels'])->toContain($ch1->id, $ch2->id)
        ->and($profiles[1]['name'])->toBe('Sports')
        ->and($profiles[1]['channels'])->toContain($ch3->id);
});

it('excludes disabled channels and VOD from profiles', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create(['name' => 'Mixed']);

    Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => false,
        'is_vod' => false,
    ]);
    Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => true,
        'is_vod' => true,
    ]);

    $accessToken = getAccessToken();

    $this->getJson('/api/channels/profiles/', [
        'Authorization' => "Bearer {$accessToken}",
    ])->assertOk()->assertJsonCount(0);
});

it('rejects unauthenticated profile requests', function () {
    $this->getJson('/api/channels/profiles/')
        ->assertStatus(401);
});

// ──────────────────────────────────────────────────────────────────────────────
// GET /api/channels/channels/ — Channels
// ──────────────────────────────────────────────────────────────────────────────

it('returns channels in dispatcharr format with stable uuid', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();

    $channel = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => true,
        'is_vod' => false,
        'name' => 'Test Channel',
        'channel' => 42,
        'source_id' => '393573',
    ]);

    $accessToken = getAccessToken();

    $response = $this->getJson('/api/channels/channels/?include_streams=true', [
        'Authorization' => "Bearer {$accessToken}",
    ]);

    $response->assertOk()->assertJsonCount(1);

    $data = $response->json(0);
    expect($data['id'])->toBe($channel->id)
        ->and($data['uuid'])->toBe($channel->uuid)
        ->and($data['uuid'])->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/')
        ->and($data['name'])->toBe('Test Channel')
        ->and($data['channel_number'])->toBe(42)
        ->and($data['tvc_guide_stationid'])->toBe('')
        ->and($data['streams'])->toBeArray()->toHaveCount(1)
        ->and($data['streams'][0]['id'])->toBe($channel->id)
        ->and($data['streams'][0]['stream_id'])->toBe(393573);
});

it('returns stable uuid that persists across requests', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();

    $channel = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => true,
        'is_vod' => false,
    ]);

    $accessToken = getAccessToken();

    $response1 = $this->getJson('/api/channels/channels/', [
        'Authorization' => "Bearer {$accessToken}",
    ]);
    $response2 = $this->getJson('/api/channels/channels/', [
        'Authorization' => "Bearer {$accessToken}",
    ]);

    expect($response1->json('0.uuid'))->toBe($response2->json('0.uuid'))
        ->and($response1->json('0.uuid'))->toBe($channel->uuid);
});

it('includes stream_stats when probed', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();

    Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => true,
        'is_vod' => false,
        'stream_stats' => [
            ['stream' => [
                'codec_type' => 'video',
                'codec_name' => 'h264',
                'width' => 1920,
                'height' => 1080,
                'avg_frame_rate' => '30/1',
                'bit_rate' => '5000000',
                'profile' => 'High',
                'level' => 41,
                'bits_per_raw_sample' => '8',
                'refs' => 4,
            ]],
            ['stream' => [
                'codec_type' => 'audio',
                'codec_name' => 'aac',
                'channels' => 2,
                'sample_rate' => '48000',
                'bit_rate' => '128000',
                'tags' => ['language' => 'eng'],
            ]],
        ],
    ]);

    $accessToken = getAccessToken();

    $response = $this->getJson('/api/channels/channels/?include_streams=true', [
        'Authorization' => "Bearer {$accessToken}",
    ]);

    $stats = $response->json('0.streams.0.stream_stats');
    expect($stats)->not->toBeNull()
        ->and($stats['resolution'])->toBe('1920x1080')
        ->and($stats['video_codec'])->toBe('h264')
        ->and($stats['video_ref_frames'])->toBe(4)
        ->and($stats['audio_codec'])->toBe('aac')
        ->and($stats['audio_language'])->toBe('eng');
});

it('excludes streams when include_streams is false', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();

    Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => true,
        'is_vod' => false,
    ]);

    $accessToken = getAccessToken();

    $response = $this->getJson('/api/channels/channels/', [
        'Authorization' => "Bearer {$accessToken}",
    ]);

    $response->assertOk();
    expect($response->json('0'))->not->toHaveKey('streams');
});

it('excludes disabled and VOD channels', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();

    Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => true,
        'is_vod' => false,
        'name' => 'Live Channel',
    ]);
    Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => false,
        'is_vod' => false,
    ]);
    Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => true,
        'is_vod' => true,
    ]);

    $accessToken = getAccessToken();

    $response = $this->getJson('/api/channels/channels/?include_streams=true', [
        'Authorization' => "Bearer {$accessToken}",
    ]);

    $response->assertOk()->assertJsonCount(1);
    expect($response->json('0.name'))->toBe('Live Channel');
});

it('returns X-Total-Count header for pagination', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();

    Channel::factory()->count(5)->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => true,
        'is_vod' => false,
    ]);

    $accessToken = getAccessToken();

    $response = $this->getJson('/api/channels/channels/?limit=2', [
        'Authorization' => "Bearer {$accessToken}",
    ]);

    $response->assertOk()
        ->assertJsonCount(2)
        ->assertHeader('X-Total-Count', '5');
});

it('paginates with offset parameter', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();

    Channel::factory()->count(5)->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => true,
        'is_vod' => false,
        'channel' => 1,
    ]);

    $accessToken = getAccessToken();

    $page1 = $this->getJson('/api/channels/channels/?limit=2&offset=0', [
        'Authorization' => "Bearer {$accessToken}",
    ]);
    $page2 = $this->getJson('/api/channels/channels/?limit=2&offset=2', [
        'Authorization' => "Bearer {$accessToken}",
    ]);

    $page1->assertJsonCount(2);
    $page2->assertJsonCount(2);

    // IDs should not overlap between pages
    $ids1 = collect($page1->json())->pluck('id');
    $ids2 = collect($page2->json())->pluck('id');
    expect($ids1->intersect($ids2))->toBeEmpty();
});

// ──────────────────────────────────────────────────────────────────────────────
// GET /proxy/ts/stream/{uuid} — Proxy Stream
// ──────────────────────────────────────────────────────────────────────────────

it('redirects to stream URL with a valid dispatcharr uuid', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();

    $channel = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => true,
        'is_vod' => false,
        'url' => 'http://provider.example.com/live/stream/123.ts',
    ]);

    $response = $this->get("/proxy/ts/stream/{$channel->uuid}");

    $response->assertRedirect('http://provider.example.com/live/stream/123.ts');
});

it('returns 404 for non-existent dispatcharr uuid', function () {
    $this->getJson('/proxy/ts/stream/00000000-0000-0000-0000-000000000000')
        ->assertStatus(404);
});

it('returns 404 for disabled channel uuid', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();

    $channel = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => false,
        'is_vod' => false,
    ]);

    $this->getJson("/proxy/ts/stream/{$channel->uuid}")
        ->assertStatus(404);
});

// ──────────────────────────────────────────────────────────────────────────────
// GET /api/vod/movies/{streamId}/ — VOD Movie Detail
// ──────────────────────────────────────────────────────────────────────────────

it('returns VOD movie detail with uuid', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();

    $channel = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => true,
        'is_vod' => true,
        'source_id' => '12345',
    ]);

    $accessToken = getAccessToken();

    $response = $this->getJson('/api/vod/movies/12345/', [
        'Authorization' => "Bearer {$accessToken}",
    ]);

    $response->assertOk()
        ->assertJsonPath('id', $channel->id)
        ->assertJsonPath('uuid', $channel->uuid);
});

it('returns 404 for non-existent VOD movie', function () {
    $accessToken = getAccessToken();

    $this->getJson('/api/vod/movies/99999/', [
        'Authorization' => "Bearer {$accessToken}",
    ])->assertStatus(404);
});

// ──────────────────────────────────────────────────────────────────────────────
// GET /api/vod/movies/{streamId}/providers/ — VOD Movie Providers
// ──────────────────────────────────────────────────────────────────────────────

it('returns VOD movie providers', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();

    $channel = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => true,
        'is_vod' => true,
        'source_id' => '12345',
        'name' => 'Test Movie',
    ]);

    $accessToken = getAccessToken();

    $response = $this->getJson('/api/vod/movies/12345/providers/', [
        'Authorization' => "Bearer {$accessToken}",
    ]);

    $response->assertOk()
        ->assertJsonCount(1);

    $provider = $response->json(0);
    expect($provider['id'])->toBe($channel->id)
        ->and($provider['stream_id'])->toBe(12345)
        ->and($provider['name'])->toBe('Test Movie');
});

it('returns empty array for non-existent VOD providers', function () {
    $accessToken = getAccessToken();

    $this->getJson('/api/vod/movies/99999/providers/', [
        'Authorization' => "Bearer {$accessToken}",
    ])->assertOk()->assertJsonCount(0);
});

// ──────────────────────────────────────────────────────────────────────────────
// Auto-generation of uuid
// ──────────────────────────────────────────────────────────────────────────────

it('auto-generates uuid on channel creation', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();

    $channel = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'uuid' => null,
    ]);

    expect($channel->uuid)->not->toBeNull()
        ->and($channel->uuid)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

it('preserves explicitly set uuid', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();
    $customUuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    $channel = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'uuid' => $customUuid,
    ]);

    expect($channel->uuid)->toBe($customUuid);
});

// ──────────────────────────────────────────────────────────────────────────────
// Helper
// ──────────────────────────────────────────────────────────────────────────────

function getAccessToken(): string
{
    return test()->postJson('/api/accounts/token/', [
        'username' => test()->username,
        'password' => test()->password,
    ])->json('access');
}
