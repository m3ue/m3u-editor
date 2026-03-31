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

it('returns channels in dispatcharr format', function () {
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
        ->and($data['uuid'])->toBeString()
        ->and($data['name'])->toBe('Test Channel')
        ->and($data['channel_number'])->toBe(42)
        ->and($data['streams'])->toBeArray()->toHaveCount(1)
        ->and($data['streams'][0]['id'])->toBe($channel->id)
        ->and($data['streams'][0]['stream_id'])->toBe(393573);
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
            ]],
            ['stream' => [
                'codec_type' => 'audio',
                'codec_name' => 'aac',
                'channels' => 2,
                'sample_rate' => '48000',
                'bit_rate' => '128000',
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
        ->and($stats['audio_codec'])->toBe('aac');
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

// ──────────────────────────────────────────────────────────────────────────────
// GET /proxy/ts/stream/{token} — Proxy Stream
// ──────────────────────────────────────────────────────────────────────────────

it('redirects to stream URL with a valid stream token', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();

    $channel = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => true,
        'is_vod' => false,
        'url' => 'http://provider.example.com/live/stream/123.ts',
    ]);

    $token = DispatcharrAuthMiddleware::createStreamToken(
        $channel->id,
        $this->playlist->id,
        Playlist::class
    );

    $response = $this->get("/proxy/ts/stream/{$token}");

    $response->assertRedirect('http://provider.example.com/live/stream/123.ts');
});

it('returns uuid as signed stream token in channels response', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();

    Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => true,
        'is_vod' => false,
    ]);

    $accessToken = getAccessToken();

    $response = $this->getJson('/api/channels/channels/?include_streams=true', [
        'Authorization' => "Bearer {$accessToken}",
    ]);

    $uuid = $response->json('0.uuid');
    $streamPayload = DispatcharrAuthMiddleware::verifyStreamToken($uuid);

    expect($streamPayload)->not->toBeNull()
        ->and($streamPayload['c'])->toBeInt()
        ->and($streamPayload['p'])->toBe($this->playlist->id)
        ->and($streamPayload['t'])->toBe(Playlist::class);
});

it('rejects tampered stream tokens', function () {
    $this->get('/proxy/ts/stream/tampered.abcdef1234567890')
        ->assertStatus(403);
});

it('rejects stream token for disabled channel', function () {
    $group = Group::factory()->for($this->playlist)->for($this->user)->create();

    $channel = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'group_id' => $group->id,
        'enabled' => false,
        'is_vod' => false,
    ]);

    $token = DispatcharrAuthMiddleware::createStreamToken(
        $channel->id,
        $this->playlist->id,
        Playlist::class
    );

    $this->get("/proxy/ts/stream/{$token}")
        ->assertStatus(404);
});

it('rejects stream token with non-existent channel', function () {
    $token = DispatcharrAuthMiddleware::createStreamToken(
        999999,
        $this->playlist->id,
        Playlist::class
    );

    $this->get("/proxy/ts/stream/{$token}")
        ->assertStatus(404);
});

// ──────────────────────────────────────────────────────────────────────────────
// Stream Token Unit Tests
// ──────────────────────────────────────────────────────────────────────────────

it('creates and verifies stream tokens roundtrip', function () {
    $token = DispatcharrAuthMiddleware::createStreamToken(93, 1, Playlist::class);

    expect($token)->toBeString()
        ->and($token)->not->toContain('+')
        ->and($token)->not->toContain('/')
        ->and($token)->not->toContain('=');

    $payload = DispatcharrAuthMiddleware::verifyStreamToken($token);

    expect($payload)->not->toBeNull()
        ->and($payload['c'])->toBe(93)
        ->and($payload['p'])->toBe(1)
        ->and($payload['t'])->toBe(Playlist::class);
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
