<?php

use App\DataObjects\ClientCapabilities;
use App\Http\Controllers\XtreamStreamController;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use App\Models\Season;
use App\Models\Series;
use App\Models\StreamProfile;
use App\Models\User;
use App\Services\M3uProxyService;
use App\Services\PlaybackCapabilityService;
use App\Services\PlaylistService;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Fixture Helpers
|--------------------------------------------------------------------------
*/

function tvMakeClient(array $overrides = []): ClientCapabilities
{
    return ClientCapabilities::fromArray(array_merge([
        'profile' => 'default',
        'platform' => 'android',
        'backend' => 'exoplayer',
        'video_codecs' => ['h264'],
        'audio_codecs' => ['aac'],
        'containers' => ['mpegts'],
    ], $overrides));
}

function tvMakeStats(array $video = [], string $audioCodec = 'aac', string $format = 'mpegts'): array
{
    return [
        ['stream' => array_merge(['codec_type' => 'video', 'codec_name' => 'h264', 'width' => 1920, 'height' => 1080], $video)],
        ['stream' => ['codec_type' => 'audio', 'codec_name' => $audioCodec]],
        ['format' => ['format_name' => $format]],
    ];
}

function tvCreateUser(string $name = 'testuser', array $overrides = []): User
{
    return User::factory()->admin()->create(array_merge(['name' => $name], $overrides));
}

function tvCreatePlaylist(User $user, string $name = 'Test Playlist'): Playlist
{
    DB::table('playlists')->insert([
        'user_id' => $user->id,
        'name' => $name,
        'uuid' => Str::uuid()->toString(),
        'id_channel_by' => 'stream_id',
        'status' => 'completed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Playlist::find(DB::getPdo()->lastInsertId());
}

function tvCreateAuth(User $user, Playlist $playlist, string $username, string $password): PlaylistAuth
{
    $auth = PlaylistAuth::factory()->for($user)->create([
        'username' => $username,
        'password' => $password,
        'enabled' => true,
    ]);
    $auth->assignTo($playlist);

    return $auth;
}

function tvCreateAlias(User $user, Playlist $playlist, array $overrides = []): PlaylistAlias
{
    return PlaylistAlias::create(array_merge([
        'name' => 'TV Alias',
        'uuid' => Str::uuid()->toString(),
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'username' => 'alias_user',
        'password' => 'alias_pass',
    ], $overrides));
}

function tvCreateChannel(User $user, Playlist $playlist, array $overrides = []): Channel
{
    $defaults = [
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'name' => 'Test Channel',
        'enabled' => true,
        'uuid' => Str::uuid()->toString(),
        'url' => 'https://example.com/stream/123.ts',
        'stream_stats' => json_encode(tvMakeStats()),
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $id = DB::table('channels')->insertGetId(array_merge($defaults, $overrides));

    return Channel::find($id);
}

function tvCreateSeries(User $user, Playlist $playlist, string $name = 'Test Series'): Series
{
    $id = DB::table('series')->insertGetId([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'name' => $name,
        'enabled' => true,
        'import_batch_no' => 'test-batch',
        'cover' => '',
        'backdrop_path' => '{}',
        'metadata' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Series::find($id);
}

function tvCreateSeason(User $user, Playlist $playlist, Series $series, string $name = 'Season 1'): Season
{
    $id = DB::table('seasons')->insertGetId([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'name' => $name,
        'import_batch_no' => 'test-batch',
        'season_number' => 1,
        'episode_count' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return Season::find($id);
}

function tvCreateEpisode(
    User $user,
    Playlist $playlist,
    Series $series,
    Season $season,
    string $title = 'Test Episode',
    array $overrides = [],
): Episode {
    $defaults = [
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'series_id' => $series->id,
        'season_id' => $season->id,
        'title' => $title,
        'enabled' => true,
        'import_batch_no' => 'test-batch',
        'container_extension' => 'mkv',
        'episode_num' => 1,
        'season' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    $id = DB::table('episodes')->insertGetId(array_merge($defaults, $overrides));

    return Episode::find($id);
}

function tvIncompatibleCaps(array $overrides = []): array
{
    return array_merge([
        'profile' => 'default',
        'platform' => 'android',
        'backend' => 'exoplayer',
        'video_codecs' => ['hevc'],
        'audio_codecs' => ['aac'],
        'containers' => ['mpegts'],
    ], $overrides);
}

function tvCreateTranscodeProfile(User $user, array $overrides = []): StreamProfile
{
    return StreamProfile::factory()->for($user)->create(array_merge([
        'backend' => 'ffmpeg',
        'format' => 'ts',
        'args' => '-c:v libx265 -c:a aac -f mpegts',
    ], $overrides));
}

function tvTranscodeOutput(array $overrides = []): array
{
    return array_merge([
        'video_codec' => 'h264',
        'audio_codec' => 'aac',
        'container' => 'mpegts',
        'max_height' => null,
        'max_bitrate_kbps' => null,
        'hdr' => null,
    ], $overrides);
}

/*
|--------------------------------------------------------------------------
| Datasets
|--------------------------------------------------------------------------
*/

dataset('special_credentials', [
    'slash' => ['my/user', 'my/pass'],
    'space' => ['my user', 'my pass'],
    'plus' => ['user+name', 'pass+word'],
    'percent' => ['user%name', 'pass%word'],
    'at' => ['user@test', 'pass@word'],
    'unicode' => ['usér', 'pässword'],
    'reserved' => ['user?name', 'pass#word:part'],
]);

/*
|--------------------------------------------------------------------------
| PlaybackCapabilityService
|--------------------------------------------------------------------------
*/

describe('PlaybackCapabilityService', function () {
    it('returns direct_play when all video/audio/container match', function () {
        $result = PlaybackCapabilityService::decide(tvMakeClient(), tvMakeStats(), canTranscode: false);

        expect($result['mode'])->toBe('direct_play');
    });

    it('returns unsupported when capabilities cannot be proven from missing metadata', function () {
        $result = PlaybackCapabilityService::decide(tvMakeClient(), null, canTranscode: false);

        expect($result['mode'])->toBe('unsupported')
            ->and($result['reason'])->toContain('unknown');
    });

    it('fails closed when a declared source constraint is unknown', function (array $capabilities, callable $removeMetadata, string $reason) {
        $stats = tvMakeStats();
        $removeMetadata($stats);

        $result = PlaybackCapabilityService::decide(
            tvMakeClient($capabilities),
            $stats,
            canTranscode: false,
        );

        expect($result['mode'])->toBe('unsupported')
            ->and($result['reason'])->toContain($reason);
    })->with([
        'height' => [
            ['max_height' => 720],
            function (array &$stats): void {
                unset($stats[0]['stream']['height']);
            },
            'Source height is unknown',
        ],
        'bitrate' => [
            ['max_bitrate_kbps' => 5000],
            function (array &$stats): void {
                unset($stats[0]['stream']['bit_rate'], $stats[1]['stream']['bit_rate'], $stats[2]['format']['bit_rate']);
            },
            'Source bitrate is unknown',
        ],
        'HDR' => [
            ['hdr' => false],
            function (array &$stats): void {
                unset(
                    $stats[0]['stream']['hdr'],
                    $stats[0]['stream']['color_transfer'],
                    $stats[0]['stream']['color_primaries'],
                );
            },
            'Source HDR status is unknown',
        ],
    ]);

    it('requires concrete transcode limits when constrained source metadata is unknown', function () {
        $stats = tvMakeStats();
        unset($stats[0]['stream']['height']);

        $unknownOutput = PlaybackCapabilityService::decide(
            tvMakeClient(['max_height' => 720]),
            $stats,
            canTranscode: true,
            transcodeOutput: tvTranscodeOutput(),
        );
        $boundedOutput = PlaybackCapabilityService::decide(
            tvMakeClient(['max_height' => 720]),
            $stats,
            canTranscode: true,
            transcodeOutput: tvTranscodeOutput(['max_height' => 720]),
        );

        expect($unknownOutput['mode'])->toBe('unsupported')
            ->and($boundedOutput['mode'])->toBe('transcode');
    });

    it('returns transcode when video codec not supported and transcode available', function () {
        $result = PlaybackCapabilityService::decide(
            tvMakeClient(),
            tvMakeStats(['codec_name' => 'hevc']),
            canTranscode: true,
            transcodeOutput: tvTranscodeOutput(),
        );

        expect($result['mode'])->toBe('transcode');
    });

    it('returns unsupported when video codec not supported and no transcode', function () {
        $result = PlaybackCapabilityService::decide(
            tvMakeClient(),
            tvMakeStats(['codec_name' => 'vp9']),
            canTranscode: false,
        );

        expect($result['mode'])->toBe('unsupported');
    });

    it('returns transcode when audio codec not supported', function () {
        $result = PlaybackCapabilityService::decide(
            tvMakeClient(),
            tvMakeStats(audioCodec: 'ac3'),
            canTranscode: true,
            transcodeOutput: tvTranscodeOutput(),
        );

        expect($result['mode'])->toBe('transcode');
    });

    it('returns transcode when container not supported', function () {
        $result = PlaybackCapabilityService::decide(
            tvMakeClient(['containers' => ['mp4']]),
            tvMakeStats(),
            canTranscode: true,
            transcodeOutput: tvTranscodeOutput(['container' => 'mp4']),
        );

        expect($result['mode'])->toBe('transcode');
    });

    it('returns transcode when height exceeds client max_height', function () {
        $result = PlaybackCapabilityService::decide(
            tvMakeClient(['max_height' => 720]),
            tvMakeStats(['height' => 1080]),
            canTranscode: true,
            transcodeOutput: tvTranscodeOutput(['max_height' => 720]),
        );

        expect($result['mode'])->toBe('transcode');
    });

    it('returns direct_play when height within client max_height', function () {
        $result = PlaybackCapabilityService::decide(
            tvMakeClient(['max_height' => 1080]),
            tvMakeStats(['height' => 720]),
            canTranscode: false,
        );

        expect($result['mode'])->toBe('direct_play');
    });

    it('returns transcode when bitrate exceeds client max_bitrate_kbps', function () {
        $result = PlaybackCapabilityService::decide(
            tvMakeClient(['max_bitrate_kbps' => 5000]),
            tvMakeStats(['bit_rate' => '8000000']),
            canTranscode: true,
            transcodeOutput: tvTranscodeOutput(['max_bitrate_kbps' => 5000]),
        );

        expect($result['mode'])->toBe('transcode');
    });

    it('returns transcode when client does not support HDR and source is HDR', function () {
        $result = PlaybackCapabilityService::decide(
            tvMakeClient(['hdr' => false]),
            tvMakeStats(['hdr' => 'HDR10']),
            canTranscode: true,
            transcodeOutput: tvTranscodeOutput(['hdr' => false]),
        );

        expect($result['mode'])->toBe('transcode');
    });

    it('returns direct_play when persisted metadata proves the source is SDR', function () {
        $result = PlaybackCapabilityService::decide(
            tvMakeClient(['hdr' => false]),
            tvMakeStats(['color_transfer' => 'bt709']),
            canTranscode: false,
        );

        expect($result['mode'])->toBe('direct_play')
            ->and($result['source']['hdr'])->toBeFalse();
    });

    it('detects HDR from standard ffprobe metadata', function () {
        $stats = tvMakeStats([
            'color_transfer' => 'smpte2084',
            'side_data_list' => [
                ['side_data_type' => 'Mastering display metadata'],
            ],
        ]);

        $result = PlaybackCapabilityService::decide(
            tvMakeClient(['hdr' => false]),
            $stats,
            canTranscode: false,
        );

        expect($result['mode'])->toBe('unsupported')
            ->and($result['source']['hdr'])->toBeTrue();
    });

    it('uses format bitrate when stream bitrate is absent', function () {
        $stats = tvMakeStats();
        $stats[2]['format']['bit_rate'] = '8000000';

        $result = PlaybackCapabilityService::decide(
            tvMakeClient(['max_bitrate_kbps' => 5000]),
            $stats,
            canTranscode: false,
        );

        expect($result['mode'])->toBe('unsupported')
            ->and($result['source']['bitrate_kbps'])->toBe(8000);
    });

    it('requires a transcode profile with compatible declared output', function () {
        $profile = new StreamProfile([
            'backend' => 'ffmpeg',
            'format' => 'ts',
            'args' => '-c:v libx265 -c:a aac -f mpegts',
        ]);
        $output = PlaybackCapabilityService::inspectTranscodeOutput($profile);

        $result = PlaybackCapabilityService::decide(
            tvMakeClient(['video_codecs' => ['hevc']]),
            tvMakeStats(['codec_name' => 'h264']),
            canTranscode: true,
            transcodeOutput: $output,
        );

        expect($result['mode'])->toBe('transcode')
            ->and($result['output']['video_codec'])->toBe('hevc')
            ->and($result['output']['audio_codec'])->toBe('aac')
            ->and($result['output']['container'])->toBe('mpegts');
    });

    it('only declares SDR output for an explicit complete tone mapping recipe', function () {
        $incomplete = new StreamProfile([
            'backend' => 'ffmpeg',
            'format' => 'ts',
            'args' => '-c:v libx264 -vf tonemap -c:a aac -f mpegts',
        ]);
        $complete = new StreamProfile([
            'backend' => 'ffmpeg',
            'format' => 'ts',
            'args' => '-c:v libx264 -vf tonemap -color_trc bt709 -color_primaries bt709 -colorspace bt709 -c:a aac -f mpegts',
        ]);

        expect(PlaybackCapabilityService::inspectTranscodeOutput($incomplete)['hdr'])->toBeNull()
            ->and(PlaybackCapabilityService::inspectTranscodeOutput($complete)['hdr'])->toBeFalse();
    });

    it('rejects ambiguous or incomplete FFmpeg output metadata', function () {
        $missingAudio = new StreamProfile([
            'backend' => 'ffmpeg',
            'format' => 'ts',
            'args' => '-c:v libx264 -f mpegts',
        ]);
        $overriddenWithCopy = new StreamProfile([
            'backend' => 'ffmpeg',
            'format' => 'ts',
            'args' => '-c:v libx264 -c:v copy -c:a aac -f mpegts',
        ]);

        expect(PlaybackCapabilityService::inspectTranscodeOutput($missingAudio))->toBeNull()
            ->and(PlaybackCapabilityService::inspectTranscodeOutput($overriddenWithCopy))->toBeNull();
    });

    it('requires concrete codecs and container for output compatibility', function () {
        $result = PlaybackCapabilityService::decide(
            tvMakeClient([
                'video_codecs' => [],
                'audio_codecs' => [],
                'containers' => [],
                'max_height' => 720,
            ]),
            tvMakeStats(['height' => 1080]),
            canTranscode: true,
            transcodeOutput: tvTranscodeOutput([
                'video_codec' => null,
                'audio_codec' => null,
                'container' => null,
                'max_height' => 720,
            ]),
        );

        expect($result['mode'])->toBe('unsupported');
    });

    it('rejects explicit output limits above client capabilities', function () {
        $result = PlaybackCapabilityService::decide(
            tvMakeClient([
                'max_height' => 720,
                'max_bitrate_kbps' => 5000,
            ]),
            tvMakeStats(['height' => 480, 'bit_rate' => '1000000']),
            canTranscode: true,
            transcodeOutput: tvTranscodeOutput([
                'max_height' => 1080,
                'max_bitrate_kbps' => 8000,
            ]),
        );

        expect($result['mode'])->toBe('direct_play');

        $result = PlaybackCapabilityService::decide(
            tvMakeClient([
                'video_codecs' => ['hevc'],
                'max_height' => 720,
                'max_bitrate_kbps' => 5000,
            ]),
            tvMakeStats(['height' => 480, 'bit_rate' => '1000000']),
            canTranscode: true,
            transcodeOutput: tvTranscodeOutput([
                'video_codec' => 'hevc',
                'max_height' => 1080,
                'max_bitrate_kbps' => 8000,
            ]),
        );

        expect($result['mode'])->toBe('unsupported');
    });

    it('is case-insensitive when matching codecs', function () {
        $result = PlaybackCapabilityService::decide(
            tvMakeClient(['video_codecs' => ['H264', 'HEVC'], 'audio_codecs' => ['AAC'], 'containers' => ['MPEGTS']]),
            tvMakeStats(format: 'mpegts,mpegtsraw'),
            canTranscode: false,
        );

        expect($result['mode'])->toBe('direct_play');
    });

    it('understands codec aliases (hevc/h265)', function () {
        $result = PlaybackCapabilityService::decide(
            tvMakeClient(['video_codecs' => ['h265']]),
            tvMakeStats(['codec_name' => 'hevc']),
            canTranscode: false,
        );

        expect($result['mode'])->toBe('direct_play');
    });

    it('understands container aliases (ts/mpegts)', function () {
        $result = PlaybackCapabilityService::decide(
            tvMakeClient(['containers' => ['ts']]),
            tvMakeStats(),
            canTranscode: false,
        );

        expect($result['mode'])->toBe('direct_play');
    });

    it('understands mpegtsraw format_name as mpegts container', function () {
        $result = PlaybackCapabilityService::decide(
            tvMakeClient(),
            tvMakeStats(format: 'mpegtsraw'),
            canTranscode: false,
        );

        expect($result['mode'])->toBe('direct_play');
    });

    it('returns source metadata in the response', function () {
        $result = PlaybackCapabilityService::decide(tvMakeClient(), tvMakeStats(), canTranscode: false);

        expect($result['source']['video_codec'])->toBe('h264');
        expect($result['source']['audio_codec'])->toBe('aac');
        expect($result['source']['container'])->toBe('mpegts');
        expect($result['source']['width'])->toBe(1920);
        expect($result['source']['height'])->toBe(1080);
        expect($result['source']['hdr'])->toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| ClientCapabilities
|--------------------------------------------------------------------------
*/

describe('ClientCapabilities', function () {
    it('normalizes codec aliases', function () {
        expect(ClientCapabilities::normalizeCodec('hevc'))->toBe('hevc');
        expect(ClientCapabilities::normalizeCodec('h265'))->toBe('hevc');
        expect(ClientCapabilities::normalizeCodec('H.265'))->toBe('hevc');
        expect(ClientCapabilities::normalizeCodec('h264'))->toBe('h264');
        expect(ClientCapabilities::normalizeCodec('avc'))->toBe('h264');
        expect(ClientCapabilities::normalizeCodec('mpeg2video'))->toBe('mpeg2video');
    });

    it('normalizes container aliases', function () {
        expect(ClientCapabilities::normalizeContainer('mpegts'))->toBe('mpegts');
        expect(ClientCapabilities::normalizeContainer('mpegtsraw'))->toBe('mpegts');
        expect(ClientCapabilities::normalizeContainer('ts'))->toBe('mpegts');
        expect(ClientCapabilities::normalizeContainer('hls'))->toBe('hls');
        expect(ClientCapabilities::normalizeContainer('m3u8'))->toBe('hls');
    });

    it('parses from array', function () {
        $capabilities = ClientCapabilities::fromArray([
            'profile' => 'default',
            'platform' => 'android',
            'backend' => 'exoplayer',
            'video_codecs' => ['h264', 'h265'],
            'audio_codecs' => ['aac', 'ac3'],
            'containers' => ['mpegts', 'mp4'],
            'max_height' => 1080,
            'max_bitrate_kbps' => 10000,
            'hdr' => true,
        ]);

        expect($capabilities->profile)->toBe('default');
        expect($capabilities->platform)->toBe('android');
        expect($capabilities->backend)->toBe('exoplayer');
        expect($capabilities->videoCodecs)->toBe(['h264', 'hevc']);
        expect($capabilities->audioCodecs)->toBe(['aac', 'ac3']);
        expect($capabilities->containers)->toBe(['mpegts', 'mp4']);
        expect($capabilities->maxHeight)->toBe(1080);
        expect($capabilities->maxBitrateKbps)->toBe(10000);
        expect($capabilities->hdr)->toBeTrue();
    });

    it('handles empty capabilities gracefully', function () {
        $capabilities = ClientCapabilities::fromArray([
            'profile' => 'default',
            'platform' => 'android',
            'backend' => 'exoplayer',
        ]);

        expect($capabilities->videoCodecs)->toBe([]);
        expect($capabilities->audioCodecs)->toBe([]);
        expect($capabilities->containers)->toBe([]);
        expect($capabilities->maxHeight)->toBeNull();
        expect($capabilities->maxBitrateKbps)->toBeNull();
        expect($capabilities->hdr)->toBeNull();
    });
});

/*
|--------------------------------------------------------------------------
| POST stream/resolve endpoint
|--------------------------------------------------------------------------
*/

describe('POST stream/resolve endpoint', function () {
    beforeEach(function () {
        $this->withoutMiddleware(ThrottleRequestsWithRedis::class);
        $this->user = tvCreateUser();
        $this->playlist = tvCreatePlaylist($this->user);
        tvCreateAuth($this->user, $this->playlist, 'tv_user', 'tv_pass');
        $this->channel = tvCreateChannel($this->user, $this->playlist);
        $this->resolveUrl = route('tv.stream.resolve.basic');
        $this->withBasicAuth('tv_user', 'tv_pass');
    });

    it('authenticates the credential-free route with HTTP Basic auth', function () {
        $response = $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $this->channel->id,
        ]);

        $response->assertOk()->assertJsonPath('mode', 'direct_play');
        expect($this->resolveUrl)->not->toContain('tv_user')->not->toContain('tv_pass');
    });

    it('returns 401 with invalid credentials', function () {
        $this->withBasicAuth('wrong', 'wrong')->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => 1,
        ])->assertUnauthorized();
    });

    it('returns 401 without HTTP Basic credentials', function () {
        $this->withHeaders(['Authorization' => ''])->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $this->channel->id,
        ])->assertUnauthorized();
    });

    it('returns 404 for non-existent stream', function () {
        $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => 99999,
        ])->assertNotFound();
    });

    it('returns direct_play mode when no client_capabilities sent', function () {
        $response = $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $this->channel->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'direct_play')
            ->assertJsonPath('reason', 'No client capabilities provided')
            ->assertJsonStructure(['mode', 'url', 'reason', 'source']);
    });

    it('returns direct_play when client capabilities are compatible', function () {
        $response = $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $this->channel->id,
            'client_capabilities' => [
                'profile' => 'default',
                'platform' => 'android',
                'backend' => 'exoplayer',
                'video_codecs' => ['h264'],
                'audio_codecs' => ['aac'],
                'containers' => ['mpegts'],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'direct_play')
            ->assertJsonStructure(['mode', 'url', 'reason', 'source']);
        expect($response->json('source'))->toHaveKeys([
            'video_codec', 'audio_codec', 'container', 'width', 'height', 'hdr',
        ]);
    });

    it('returns unsupported when client capabilities incompatible and no profile', function () {
        $response = $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $this->channel->id,
            'client_capabilities' => tvIncompatibleCaps(),
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'unsupported')
            ->assertJsonPath('url', null);
    });

    it('returns transcode when client capabilities incompatible and profile available', function () {
        $profile = tvCreateTranscodeProfile($this->user);
        $this->channel->update(['stream_profile_id' => $profile->id]);
        $this->channel->load('streamProfile');

        $mockUrl = 'https://proxy.example.com/hls/abc123/playlist.m3u8';
        $this->mock(M3uProxyService::class, function ($mock) use ($mockUrl) {
            $mock->shouldReceive('getChannelUrl')->once()->andReturn($mockUrl);
        });

        $response = $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $this->channel->id,
            'client_capabilities' => tvIncompatibleCaps(),
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'transcode')
            ->assertJsonPath('url', $mockUrl);
    });

    it('returns unsupported when user cannot use proxy', function () {
        $this->user->update(['is_admin' => false, 'permissions' => []]);
        $profile = tvCreateTranscodeProfile($this->user);
        $this->channel->update(['stream_profile_id' => $profile->id]);

        $response = $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $this->channel->id,
            'client_capabilities' => tvIncompatibleCaps(),
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'unsupported')
            ->assertJsonPath('url', null);
    });

    it('returns 422 for invalid client_capabilities types', function () {
        $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $this->channel->id,
            'client_capabilities' => 'not-an-array',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['client_capabilities']);

        $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $this->channel->id,
            'client_capabilities' => ['video_codecs' => 'not-an-array'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['client_capabilities.video_codecs']);

        $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $this->channel->id,
            'client_capabilities' => ['max_height' => 'not-an-integer'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['client_capabilities.max_height']);
    });

    it('returns editor Xtream URL for direct_play (not upstream provider URL)', function () {
        $response = $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $this->channel->id,
        ]);

        $url = $response->json('url');
        expect(parse_url($url, PHP_URL_PATH))
            ->toBe("/api/tv/stream/playlist/{$this->playlist->id}/live/{$this->channel->id}")
            ->and(URL::hasValidSignature(Request::create($url)))->toBeTrue();
        expect($url)->not->toContain('tv_user')->not->toContain('tv_pass');
        expect($url)->not->toContain('example.com/stream/123.ts');
    });

    it('validates required fields', function () {
        $this->postJson($this->resolveUrl, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['type', 'stream_id']);
    });

    it('validates type must be live/vod/series', function () {
        $this->postJson($this->resolveUrl, [
            'type' => 'invalid',
            'stream_id' => 1,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['type']);
    });
});

/*
|--------------------------------------------------------------------------
| VOD stream resolve
|--------------------------------------------------------------------------
*/

describe('VOD stream resolve', function () {
    beforeEach(function () {
        $this->withoutMiddleware(ThrottleRequestsWithRedis::class);
        $this->user = tvCreateUser();
        $this->playlist = tvCreatePlaylist($this->user, 'VOD Playlist');
        tvCreateAuth($this->user, $this->playlist, 'vod_user', 'vod_pass');
        $this->vodChannel = tvCreateChannel($this->user, $this->playlist, [
            'name' => 'VOD Channel',
            'is_vod' => true,
            'url' => 'https://example.com/movie/456.mp4',
            'stream_stats' => json_encode(tvMakeStats(format: 'mp4')),
        ]);
        $this->vodUrl = route('tv.stream.resolve.basic');
        $this->withBasicAuth('vod_user', 'vod_pass');
    });

    it('resolves VOD channel with compatible capabilities', function () {
        $response = $this->postJson($this->vodUrl, [
            'type' => 'vod',
            'stream_id' => $this->vodChannel->id,
            'client_capabilities' => [
                'profile' => 'default',
                'platform' => 'ios',
                'backend' => 'avplayer',
                'video_codecs' => ['h264'],
                'audio_codecs' => ['aac'],
                'containers' => ['mp4'],
            ],
        ]);

        $response->assertOk()->assertJsonPath('mode', 'direct_play');
    });

    it('uses editor Xtream URL for VOD direct_play', function () {
        $response = $this->postJson($this->vodUrl, [
            'type' => 'vod',
            'stream_id' => $this->vodChannel->id,
        ]);

        $url = $response->json('url');
        expect(parse_url($url, PHP_URL_PATH))
            ->toBe("/api/tv/stream/playlist/{$this->playlist->id}/vod/{$this->vodChannel->id}")
            ->and(URL::hasValidSignature(Request::create($url)))->toBeTrue();
        expect($url)->not->toContain('vod_user')->not->toContain('vod_pass');
        expect($url)->not->toContain('example.com/movie/456.mp4');
    });
});

/*
|--------------------------------------------------------------------------
| HTTP Basic credentials
|--------------------------------------------------------------------------
*/

describe('HTTP Basic credentials', function () {
    beforeEach(function () {
        $this->withoutMiddleware(ThrottleRequestsWithRedis::class);
        $this->user = tvCreateUser();
        $this->playlist = tvCreatePlaylist($this->user, 'Cred Playlist');
    });

    it('fails closed without exposing provider credentials', function (string $username, string $password) {
        tvCreateAuth($this->user, $this->playlist, $username, $password);
        $channel = tvCreateChannel($this->user, $this->playlist, [
            'name' => 'Cred Channel',
            'url' => 'https://provider_user:provider_pass@provider.example/live/account/secret/123.ts?token=provider_secret',
        ]);

        $response = $this->withBasicAuth($username, $password)->postJson(route('tv.stream.resolve.basic'), [
            'type' => 'live',
            'stream_id' => $channel->id,
        ]);

        $response->assertOk();
        $responseUrl = $response->json('url');
        expect($responseUrl)
            ->not->toContain($username)
            ->not->toContain($password)
            ->and(URL::hasValidSignature(Request::create($responseUrl)))->toBeTrue();

        $playbackResponse = $this->withBasicAuth($username, $password)->get($responseUrl);
        $playbackResponse->assertServiceUnavailable();
        expect($playbackResponse->headers->get('Location'))->toBeNull()
            ->and($playbackResponse->getContent())->not->toContain('provider.example')
            ->not->toContain('provider_user')
            ->not->toContain('provider_pass')
            ->not->toContain('provider_secret');
    })->with('special_credentials');

    it('returns a credential-free VOD playback URL', function () {
        tvCreateAuth($this->user, $this->playlist, 'a b', 'c+d');
        $channel = tvCreateChannel($this->user, $this->playlist, [
            'name' => 'VOD Cred Channel',
            'is_vod' => true,
            'container_extension' => 'mkv',
            'url' => 'https://example.com/movie.mkv',
        ]);

        $response = $this->withBasicAuth('a b', 'c+d')->postJson(route('tv.stream.resolve.basic'), [
            'type' => 'vod',
            'stream_id' => $channel->id,
        ]);

        $response->assertOk();
        $responseUrl = $response->json('url');
        expect($responseUrl)
            ->not->toContain('a b')
            ->not->toContain('c+d')
            ->and(URL::hasValidSignature(Request::create($responseUrl)))->toBeTrue();
    });

    it('never exposes provider credentials across playback response chains', function () {
        tvCreateAuth($this->user, $this->playlist, 'chain_user', 'chain_pass');
        $providerUrl = 'https://provider_user:provider_pass@provider.example/live/account/secret/123.ts?token=provider_secret';
        $live = tvCreateChannel($this->user, $this->playlist, ['url' => $providerUrl]);
        $vod = tvCreateChannel($this->user, $this->playlist, [
            'url' => $providerUrl,
            'is_vod' => true,
            'container_extension' => 'mkv',
        ]);
        $catchup = tvCreateChannel($this->user, $this->playlist, [
            'url' => $providerUrl,
            'catchup' => 'default',
        ]);
        $series = tvCreateSeries($this->user, $this->playlist, 'Credential Series');
        $season = tvCreateSeason($this->user, $this->playlist, $series);
        $episode = tvCreateEpisode($this->user, $this->playlist, $series, $season, overrides: [
            'url' => $providerUrl,
        ]);
        $requests = [
            ['type' => 'live', 'stream_id' => $live->id],
            ['type' => 'vod', 'stream_id' => $vod->id],
            ['type' => 'series', 'stream_id' => $episode->id],
            [
                'type' => 'catchup',
                'stream_id' => $catchup->id,
                'catchup_start' => '2026-07-10T12:30:00+00:00',
                'catchup_duration_minutes' => 30,
            ],
        ];

        config(['proxy.m3u_proxy_host' => '']);

        foreach ($requests as $payload) {
            $url = $this->withBasicAuth('chain_user', 'chain_pass')
                ->postJson(route('tv.stream.resolve.basic'), $payload)
                ->assertOk()
                ->json('url');
            $playbackResponse = $this->withBasicAuth('chain_user', 'chain_pass')->get($url);

            $playbackResponse->assertServiceUnavailable();
            expect($playbackResponse->headers->get('Location'))->toBeNull()
                ->and($playbackResponse->getContent())->not->toContain('provider.example')
                ->not->toContain('provider_user')
                ->not->toContain('provider_pass')
                ->not->toContain('provider_secret');
        }
    });

    it('rejects unsigned, expired, and cross-playlist playback requests', function () {
        $otherPlaylist = tvCreatePlaylist($this->user, 'Other Playlist');
        $channel = tvCreateChannel($this->user, $this->playlist, ['name' => 'Signed Route Channel']);
        $unsignedUrl = route('tv.stream.play', [
            'playlistType' => 'playlist',
            'playlistId' => $this->playlist->id,
            'type' => 'live',
            'streamId' => $channel->id,
            'format' => 'ts',
        ]);
        $crossPlaylistUrl = URL::temporarySignedRoute('tv.stream.play', now()->addMinute(), [
            'playlistType' => 'playlist',
            'playlistId' => $otherPlaylist->id,
            'type' => 'live',
            'streamId' => $channel->id,
            'format' => 'ts',
        ]);
        $expiredUrl = URL::temporarySignedRoute('tv.stream.play', now()->subMinute(), [
            'playlistType' => 'playlist',
            'playlistId' => $this->playlist->id,
            'type' => 'live',
            'streamId' => $channel->id,
            'format' => 'ts',
        ]);

        $this->get($unsignedUrl)->assertForbidden();
        $this->get($expiredUrl)->assertForbidden();
        $this->get($crossPlaylistUrl)->assertNotFound();
    });

    it('rejects playback after the authorizing PlaylistAuth is disabled', function () {
        $auth = tvCreateAuth($this->user, $this->playlist, 'revoked_user', 'revoked_pass');
        $channel = tvCreateChannel($this->user, $this->playlist, ['name' => 'Revoked Auth Channel']);
        $url = $this->withBasicAuth('revoked_user', 'revoked_pass')->postJson(route('tv.stream.resolve.basic'), [
            'type' => 'live',
            'stream_id' => $channel->id,
        ])->json('url');

        $auth->update(['enabled' => false]);

        $this->get($url)->assertForbidden();
    });

    it('rejects disabled and wrong-type streams on signed playback', function () {
        $disabledChannel = tvCreateChannel($this->user, $this->playlist, [
            'name' => 'Disabled Playback Channel',
            'enabled' => false,
        ]);
        $vodChannel = tvCreateChannel($this->user, $this->playlist, [
            'name' => 'Wrong Type Playback Channel',
            'is_vod' => true,
        ]);

        foreach ([$disabledChannel, $vodChannel] as $channel) {
            $url = URL::temporarySignedRoute('tv.stream.play', now()->addMinute(), [
                'playlistType' => 'playlist',
                'playlistId' => $this->playlist->id,
                'type' => 'live',
                'streamId' => $channel->id,
                'format' => 'ts',
            ]);

            $this->get($url)->assertNotFound();
        }
    });

    it('rejects an episode whose parent series is disabled on signed playback', function () {
        $series = tvCreateSeries($this->user, $this->playlist, 'Disabled Playback Series');
        $season = tvCreateSeason($this->user, $this->playlist, $series);
        $episode = tvCreateEpisode($this->user, $this->playlist, $series, $season);
        $series->update(['enabled' => false]);
        $url = URL::temporarySignedRoute('tv.stream.play', now()->addMinute(), [
            'playlistType' => 'playlist',
            'playlistId' => $this->playlist->id,
            'type' => 'series',
            'streamId' => $episode->id,
            'format' => 'mkv',
        ]);

        $this->get($url)->assertNotFound();
    });

    it('rejects signed alias playback after the alias expires', function () {
        $alias = tvCreateAlias($this->user, $this->playlist);
        $channel = tvCreateChannel($this->user, $this->playlist, ['name' => 'Alias Playback Channel']);
        $url = $this->withBasicAuth('alias_user', 'alias_pass')->postJson(route('tv.stream.resolve.basic'), [
            'type' => 'live',
            'stream_id' => $channel->id,
        ])->json('url');

        $alias->update(['expires_at' => now()->subMinute()]);

        $this->get($url)->assertForbidden();
    });
});

/*
|--------------------------------------------------------------------------
| catchup stream resolve
|--------------------------------------------------------------------------
*/

describe('catchup stream resolve', function () {
    beforeEach(function () {
        $this->withoutMiddleware(ThrottleRequestsWithRedis::class);
        $this->user = tvCreateUser('catchupuser');
        $this->playlist = tvCreatePlaylist($this->user, 'Catchup Playlist');
        tvCreateAuth($this->user, $this->playlist, 'catchup_user', 'catchup_pass');
        $this->channel = tvCreateChannel($this->user, $this->playlist, [
            'name' => 'Catchup Channel',
            'catchup' => 'default',
        ]);
        $this->resolveUrl = route('tv.stream.resolve.basic');
        $this->withBasicAuth('catchup_user', 'catchup_pass');
    });

    it('validates catchup start, duration, and format', function () {
        $this->postJson($this->resolveUrl, [
            'type' => 'catchup',
            'stream_id' => $this->channel->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['catchup_start', 'catchup_duration_minutes']);

        $this->postJson($this->resolveUrl, [
            'type' => 'catchup',
            'stream_id' => $this->channel->id,
            'catchup_start' => 'tomorrow',
            'catchup_duration_minutes' => 0,
            'catchup_format' => 'mp4',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors([
                'catchup_start',
                'catchup_duration_minutes',
                'catchup_format',
            ]);

        $this->postJson($this->resolveUrl, [
            'type' => 'catchup',
            'stream_id' => $this->channel->id,
            'catchup_start' => '2026-07-10T12:30:00+00:00',
            'catchup_duration_minutes' => 30,
            'extension' => 'm3u8',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['extension']);
    });

    it('returns a signed credential-free URL and dispatches timeshift arguments', function () {
        $controller = $this->mock(XtreamStreamController::class);
        $controller->shouldReceive('handleTimeshift')
            ->once()
            ->withArgs(fn (
                Request $request,
                string $username,
                string $password,
                int $duration,
                string $date,
                int $streamId,
                string $format,
            ): bool => $username === 'catchup_user'
                && $password === 'catchup_pass'
                && $duration === 90
                && $date === '2026-07-10:12-30-00'
                && $streamId === $this->channel->id
                && $format === 'm3u8')
            ->andReturn(response('timeshift'));

        $response = $this->postJson($this->resolveUrl, [
            'type' => 'catchup',
            'stream_id' => $this->channel->id,
            'catchup_start' => '2026-07-10T14:30:00+02:00',
            'catchup_duration_minutes' => 90,
            'catchup_format' => 'm3u8',
        ]);

        $response->assertOk()->assertJsonPath('mode', 'direct_play');
        $url = $response->json('url');
        expect(parse_url($url, PHP_URL_PATH))
            ->toBe("/api/tv/stream/playlist/{$this->playlist->id}/catchup/{$this->channel->id}")
            ->and(URL::hasValidSignature(Request::create($url)))->toBeTrue()
            ->and($url)->not->toContain('catchup_user')
            ->not->toContain('catchup_pass')
            ->not->toContain('example.com');

        $this->get($url)->assertOk()->assertSee('timeshift');
    });

    it('rejects cross-playlist and VOD catchup streams', function () {
        $otherPlaylist = tvCreatePlaylist($this->user, 'Other Catchup Playlist');
        $otherChannel = tvCreateChannel($this->user, $otherPlaylist, ['catchup' => 'default']);
        $vodChannel = tvCreateChannel($this->user, $this->playlist, [
            'name' => 'Catchup VOD',
            'is_vod' => true,
            'catchup' => 'default',
        ]);

        foreach ([$otherChannel, $vodChannel] as $channel) {
            $this->postJson($this->resolveUrl, [
                'type' => 'catchup',
                'stream_id' => $channel->id,
                'catchup_start' => '2026-07-10T12:30:00+00:00',
                'catchup_duration_minutes' => 30,
            ])->assertNotFound();
        }
    });

    it('rejects catchup disabled by the source playlist or unavailable on the channel', function () {
        $this->playlist->update(['disable_catchup' => true]);

        $this->postJson($this->resolveUrl, [
            'type' => 'catchup',
            'stream_id' => $this->channel->id,
            'catchup_start' => '2026-07-10T12:30:00+00:00',
            'catchup_duration_minutes' => 30,
        ])->assertNotFound();

        $this->playlist->update(['disable_catchup' => false]);
        $this->channel->update(['catchup' => null]);

        $this->postJson($this->resolveUrl, [
            'type' => 'catchup',
            'stream_id' => $this->channel->id,
            'catchup_start' => '2026-07-10T12:30:00+00:00',
            'catchup_duration_minutes' => 30,
        ])->assertNotFound();
    });

    it('protects catchup query parameters with the signature', function () {
        $url = $this->postJson($this->resolveUrl, [
            'type' => 'catchup',
            'stream_id' => $this->channel->id,
            'catchup_start' => '2026-07-10T12:30:00+00:00',
            'catchup_duration_minutes' => 30,
            'catchup_format' => 'm3u8',
        ])->json('url');

        foreach (['duration=31', 'start=2026-07-11%3A12-30-00', 'format=ts'] as $replacement) {
            $tamperedUrl = match (true) {
                str_starts_with($replacement, 'duration') => str_replace('duration=30', $replacement, $url),
                str_starts_with($replacement, 'start') => preg_replace('/start=[^&]+/', $replacement, $url),
                default => str_replace('format=m3u8', $replacement, $url),
            };

            $this->get($tamperedUrl)->assertForbidden();
        }
    });

    it('skips catchup failovers whose source playlist disables catchup', function () {
        $this->playlist->update(['server_timezone' => 'Etc/UTC']);
        $blockedPlaylist = tvCreatePlaylist($this->user, 'Blocked Catchup Source');
        $blockedPlaylist->update(['disable_catchup' => true]);
        $blockedFailover = tvCreateChannel($this->user, $blockedPlaylist, [
            'name' => 'Blocked Catchup Failover',
            'url' => 'https://provider.example/live/user/pass/blocked.ts',
            'catchup' => 'default',
        ]);
        $eligibleFailover = tvCreateChannel($this->user, $this->playlist, [
            'name' => 'Eligible Catchup Failover',
            'url' => 'https://provider.example/live/user/pass/eligible.ts',
            'catchup' => 'default',
        ]);
        $this->channel->update(['catchup' => null]);
        foreach ([$blockedFailover, $eligibleFailover] as $sort => $failover) {
            ChannelFailover::create([
                'user_id' => $this->user->id,
                'channel_id' => $this->channel->id,
                'channel_failover_id' => $failover->id,
                'sort' => $sort,
                'metadata' => [],
            ]);
        }
        $url = $this->postJson($this->resolveUrl, [
            'type' => 'catchup',
            'stream_id' => $this->channel->id,
            'catchup_start' => '2026-07-10T12:30:00+00:00',
            'catchup_duration_minutes' => 30,
        ])->json('url');

        $this->get($url)
            ->assertRedirect()
            ->assertRedirectContains('eligible.ts');
    });

    it('keeps a shift-only primary instead of replacing it with a failover', function () {
        $this->channel->update([
            'url' => 'https://provider.example/live/user/pass/primary.ts',
            'catchup' => null,
            'shift' => 1,
        ]);
        $failover = tvCreateChannel($this->user, $this->playlist, [
            'name' => 'Catchup Failover',
            'url' => 'https://provider.example/live/user/pass/failover.ts',
            'catchup' => 'default',
        ]);
        ChannelFailover::create([
            'user_id' => $this->user->id,
            'channel_id' => $this->channel->id,
            'channel_failover_id' => $failover->id,
            'sort' => 0,
            'metadata' => [],
        ]);

        $response = app(XtreamStreamController::class)->handleTimeshift(
            Request::create('/timeshift', 'GET'),
            'catchup_user',
            'catchup_pass',
            30,
            '2026-07-10:12-30-00',
            $this->channel->id,
            'ts',
        );

        expect($response->getTargetUrl())->toContain('primary.ts');
    });
});

/*
|--------------------------------------------------------------------------
| playback URL and log secrecy
|--------------------------------------------------------------------------
*/

describe('playback URL and log secrecy', function () {
    it('does not log client capability request fields', function () {
        $user = tvCreateUser('safe_log_user');
        $playlist = tvCreatePlaylist($user, 'Safe Log Playlist');
        tvCreateAuth($user, $playlist, 'safe_log_auth', 'safe_log_password');
        $channel = tvCreateChannel($user, $playlist);
        Log::spy();

        $this->withoutMiddleware(ThrottleRequestsWithRedis::class)
            ->withBasicAuth('safe_log_auth', 'safe_log_password')
            ->postJson(route('tv.stream.resolve.basic'), [
                'type' => 'live',
                'stream_id' => $channel->id,
                'client_capabilities' => [
                    'profile' => 'secret_profile_marker',
                    'platform' => 'secret_platform_marker',
                    'backend' => 'secret_backend_marker',
                    'video_codecs' => ['h264'],
                    'audio_codecs' => ['aac'],
                    'containers' => ['mpegts'],
                ],
            ])
            ->assertOk();

        Log::shouldHaveReceived('info')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'TV stream resolve'
                && ! array_key_exists('profile', $context)
                && ! array_key_exists('platform', $context)
                && ! array_key_exists('backend', $context));
    });

    it('does not append usernames to proxy playback URLs', function () {
        config(['proxy.m3u_proxy_public_url' => 'https://proxy.example.test']);
        $service = new class extends M3uProxyService
        {
            public function proxyUrl(string $username): string
            {
                return $this->buildProxyUrl('stream-id', 'ts', $username);
            }
        };

        expect($service->proxyUrl('basic_user'))
            ->toBe('https://proxy.example.test/m3u-proxy/stream/stream-id')
            ->not->toContain('basic_user');
    });

    it('rejects proxy public URLs containing URI credentials', function () {
        config(['proxy.m3u_proxy_public_url' => 'https://proxy_user:proxy_pass@proxy.example.test']);
        $service = new class extends M3uProxyService
        {
            public function proxyUrl(): string
            {
                return $this->buildProxyUrl('stream-id', 'ts');
            }
        };

        expect(fn () => $service->proxyUrl())
            ->toThrow(Exception::class, 'Proxy public URL must not contain credentials');
    });

    it('does not log provider URLs or proxy response bodies', function () {
        config([
            'proxy.m3u_proxy_host' => 'https://proxy.example.test',
            'proxy.m3u_proxy_port' => null,
        ]);
        Http::fake(['*' => Http::response('secret proxy response body', 500)]);
        Log::spy();
        $service = new class extends M3uProxyService
        {
            public function create(string $url): void
            {
                $this->createStream($url);
            }
        };

        expect(fn () => $service->create('https://provider_user:provider_pass@provider.example/live/1.ts'))
            ->toThrow(Exception::class, 'Failed to create stream');
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Error creating/updating stream on m3u-proxy'
                && $context === ['error_type' => Exception::class]);
    });

    it('does not log transcode profile arguments', function () {
        config([
            'proxy.m3u_proxy_host' => 'https://proxy.example.test',
            'proxy.m3u_proxy_port' => null,
        ]);
        Http::fake(['*' => Http::response('secret proxy response body', 500)]);
        Log::spy();
        $profile = new StreamProfile([
            'backend' => 'ffmpeg',
            'format' => 'ts',
            'args' => '-headers secret_profile_token -c:v libx264 -c:a aac -f mpegts',
        ]);
        $service = new class extends M3uProxyService
        {
            public function transcode(string $url, StreamProfile $profile): void
            {
                $this->createTranscodedStream($url, $profile);
            }
        };

        expect(fn () => $service->transcode('https://provider.example/live/user/pass/1.ts', $profile))
            ->toThrow(Exception::class, 'Failed to create transcoded stream');
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Error creating transcoded stream on m3u-proxy'
                && $context === ['error_type' => Exception::class]);
    });

    it('does not log generated provider timeshift URLs', function () {
        $request = Request::create('/timeshift', 'GET', [
            'timeshift_duration' => 30,
            'timeshift_date' => '2026-07-10:12-30-00',
        ]);
        Log::spy();

        PlaylistService::generateTimeshiftUrl(
            $request,
            'https://provider.example/live/provider_user/provider_pass/1.ts',
            (object) ['server_timezone' => 'Etc/UTC'],
        );

        Log::shouldHaveReceived('debug')
            ->once()
            ->withArgs(fn (string $message, array $context): bool => $message === 'Generated Xtream timeshift URL'
                && array_keys($context) === ['duration_minutes']);
    });
});

/*
|--------------------------------------------------------------------------
| loadStream content type enforcement
|--------------------------------------------------------------------------
*/

describe('loadStream content type enforcement', function () {
    beforeEach(function () {
        $this->withoutMiddleware(ThrottleRequestsWithRedis::class);
        $this->user = tvCreateUser('ctypeuser');
        $this->playlist = tvCreatePlaylist($this->user, 'CType Playlist');
        tvCreateAuth($this->user, $this->playlist, 'ctype_user', 'ctype_pass');
        $this->resolveUrl = route('tv.stream.resolve.basic');
        $this->withBasicAuth('ctype_user', 'ctype_pass');
    });

    it('returns 404 when requesting live type for a VOD channel', function () {
        $channel = tvCreateChannel($this->user, $this->playlist, [
            'name' => 'VOD Mismatch',
            'is_vod' => true,
            'url' => 'https://example.com/vod.ts',
        ]);

        $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $channel->id,
        ])->assertNotFound();
    });

    it('returns 404 when requesting vod type for a live channel', function () {
        $channel = tvCreateChannel($this->user, $this->playlist, [
            'name' => 'Live Mismatch',
            'is_vod' => false,
            'url' => 'https://example.com/live.ts',
        ]);

        $this->postJson($this->resolveUrl, [
            'type' => 'vod',
            'stream_id' => $channel->id,
        ])->assertNotFound();
    });
});

/*
|--------------------------------------------------------------------------
| series stream resolve
|--------------------------------------------------------------------------
*/

describe('series stream resolve', function () {
    beforeEach(function () {
        $this->withoutMiddleware(ThrottleRequestsWithRedis::class);
        $this->user = tvCreateUser('seriesuser');
        $this->playlist = tvCreatePlaylist($this->user, 'Series Playlist');
        tvCreateAuth($this->user, $this->playlist, 'series_user', 'series_pass');
        $this->series = tvCreateSeries($this->user, $this->playlist);
        $this->season = tvCreateSeason($this->user, $this->playlist, $this->series);
        $this->episode = tvCreateEpisode($this->user, $this->playlist, $this->series, $this->season);
        $this->seriesUrl = route('tv.stream.resolve.basic');
        $this->withBasicAuth('series_user', 'series_pass');
    });

    it('resolves a series episode with direct_play', function () {
        $response = $this->postJson($this->seriesUrl, [
            'type' => 'series',
            'stream_id' => $this->episode->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'direct_play')
            ->assertJsonStructure(['mode', 'url', 'reason', 'source']);
    });

    it('returns 404 for a disabled episode', function () {
        $this->episode->update(['enabled' => false]);

        $this->postJson($this->seriesUrl, [
            'type' => 'series',
            'stream_id' => $this->episode->id,
        ])->assertNotFound();
    });

    it('returns 404 for an episode belonging to a different playlist', function () {
        $otherPlaylist = tvCreatePlaylist($this->user, 'Other Playlist');
        $otherSeries = tvCreateSeries($this->user, $otherPlaylist, 'Other Series');
        $otherSeason = tvCreateSeason($this->user, $otherPlaylist, $otherSeries, 'Season Other');
        $otherEpisode = tvCreateEpisode($this->user, $otherPlaylist, $otherSeries, $otherSeason, 'Other Episode');

        $this->postJson($this->seriesUrl, [
            'type' => 'series',
            'stream_id' => $otherEpisode->id,
        ])->assertNotFound();
    });

    it('returns 404 when the owning series is disabled', function () {
        $this->series->update(['enabled' => false]);

        $this->postJson($this->seriesUrl, [
            'type' => 'series',
            'stream_id' => $this->episode->id,
        ])->assertNotFound();
    });

    it('returns 404 for non-existent episode', function () {
        $this->postJson($this->seriesUrl, [
            'type' => 'series',
            'stream_id' => 99999,
        ])->assertNotFound();
    });
});

/*
|--------------------------------------------------------------------------
| profile precedence
|--------------------------------------------------------------------------
*/

describe('profile precedence', function () {
    beforeEach(function () {
        $this->withoutMiddleware(ThrottleRequestsWithRedis::class);
        $this->user = tvCreateUser('precuser', ['permissions' => ['use_proxy']]);
        $this->playlist = tvCreatePlaylist($this->user, 'Prec Playlist');
        tvCreateAuth($this->user, $this->playlist, 'prec_user', 'prec_pass');
        $this->resolveUrl = route('tv.stream.resolve.basic');
        $this->withBasicAuth('prec_user', 'prec_pass');
    });

    it('uses channel-level profile over playlist profile for incompatible codec', function () {
        $channelProfile = tvCreateTranscodeProfile($this->user, ['name' => 'chan-profile']);
        $playlistProfile = tvCreateTranscodeProfile($this->user, ['name' => 'pl-profile']);
        $this->playlist->update(['stream_profile_id' => $playlistProfile->id]);
        $this->playlist->load('streamProfile');

        $channel = tvCreateChannel($this->user, $this->playlist, [
            'name' => 'Prec Channel',
            'stream_profile_id' => $channelProfile->id,
        ]);

        $mockUrl = 'https://proxy.test/hls/chan/playlist.m3u8';
        $this->mock(M3uProxyService::class, function ($mock) use ($mockUrl, $channelProfile) {
            $mock->shouldReceive('getChannelUrl')
                ->once()
                ->withArgs(fn ($playlist, $channel, $request, $profile) => $profile?->id === $channelProfile->id)
                ->andReturn($mockUrl);
        });

        $response = $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $channel->id,
            'client_capabilities' => tvIncompatibleCaps(),
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'transcode')
            ->assertJsonPath('url', $mockUrl);
    });

    it('falls back to playlist profile when channel has no profile', function () {
        $playlistProfile = tvCreateTranscodeProfile($this->user, ['name' => 'pl-profile']);
        $this->playlist->update(['stream_profile_id' => $playlistProfile->id]);
        $this->playlist->load('streamProfile');

        $channel = tvCreateChannel($this->user, $this->playlist, [
            'name' => 'Prec Channel 2',
            'stream_profile_id' => null,
        ]);

        $mockUrl = 'https://proxy.test/hls/pl/playlist.m3u8';
        $this->mock(M3uProxyService::class, function ($mock) use ($mockUrl, $playlistProfile) {
            $mock->shouldReceive('getChannelUrl')
                ->once()
                ->withArgs(fn ($playlist, $channel, $request, $profile) => $profile?->id === $playlistProfile->id)
                ->andReturn($mockUrl);
        });

        $response = $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $channel->id,
            'client_capabilities' => tvIncompatibleCaps(),
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'transcode')
            ->assertJsonPath('url', $mockUrl);
    });

    it('falls back to GeneralSettings default when neither channel nor playlist has a profile', function () {
        $defaultProfile = tvCreateTranscodeProfile($this->user, ['name' => 'default-profile']);

        $mockSettings = Mockery::mock(GeneralSettings::class);
        $mockSettings->default_stream_profile_id = $defaultProfile->id;
        $mockSettings->default_vod_stream_profile_id = null;
        app()->instance(GeneralSettings::class, $mockSettings);

        $channel = tvCreateChannel($this->user, $this->playlist, [
            'name' => 'Prec Channel 3',
            'stream_profile_id' => null,
        ]);

        $mockUrl = 'https://proxy.test/hls/default/playlist.m3u8';
        $this->mock(M3uProxyService::class, function ($mock) use ($mockUrl, $defaultProfile) {
            $mock->shouldReceive('getChannelUrl')
                ->once()
                ->withArgs(fn ($playlist, $channel, $request, $profile) => $profile?->id === $defaultProfile->id)
                ->andReturn($mockUrl);
        });

        $response = $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $channel->id,
            'client_capabilities' => tvIncompatibleCaps(),
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'transcode')
            ->assertJsonPath('url', $mockUrl);
    });
});

/*
|--------------------------------------------------------------------------
| proxy disabled/unconfigured
|--------------------------------------------------------------------------
*/

describe('proxy disabled/unconfigured', function () {
    beforeEach(function () {
        $this->withoutMiddleware(ThrottleRequestsWithRedis::class);
        $this->user = tvCreateUser('proxoffuser', ['permissions' => ['use_proxy']]);
        $this->playlist = tvCreatePlaylist($this->user, 'ProxOff Playlist');
        tvCreateAuth($this->user, $this->playlist, 'proxoff_user', 'proxoff_pass');
        $this->resolveUrl = route('tv.stream.resolve.basic');
        $this->withBasicAuth('proxoff_user', 'proxoff_pass');
    });

    it('returns unsupported when proxy host is empty', function () {
        config(['proxy.m3u_proxy_host' => '']);
        $profile = tvCreateTranscodeProfile($this->user);
        $channel = tvCreateChannel($this->user, $this->playlist, [
            'name' => 'ProxOff Channel',
            'stream_profile_id' => $profile->id,
        ]);

        $response = $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $channel->id,
            'client_capabilities' => tvIncompatibleCaps(),
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'unsupported')
            ->assertJsonPath('url', null);
    });

    it('returns unsupported when proxy integration is disabled', function () {
        $profile = tvCreateTranscodeProfile($this->user);
        $this->user->update(['permissions' => []]);

        $channel = tvCreateChannel($this->user, $this->playlist, [
            'name' => 'ProxOff Channel 2',
            'stream_profile_id' => $profile->id,
        ]);

        $response = $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $channel->id,
            'client_capabilities' => tvIncompatibleCaps(),
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'unsupported')
            ->assertJsonPath('url', null);
    });
});

/*
|--------------------------------------------------------------------------
| transcode service exception
|--------------------------------------------------------------------------
*/

describe('transcode service exception', function () {
    beforeEach(function () {
        $this->withoutMiddleware(ThrottleRequestsWithRedis::class);
        $this->user = tvCreateUser('txfailuser', ['permissions' => ['use_proxy']]);
        $this->playlist = tvCreatePlaylist($this->user, 'TxFail Playlist');
        tvCreateAuth($this->user, $this->playlist, 'txfail_user', 'txfail_pass');
        $this->resolveUrl = route('tv.stream.resolve.basic');
        $this->withBasicAuth('txfail_user', 'txfail_pass');
    });

    it('returns unsupported with null url when getChannelUrl throws', function () {
        $profile = tvCreateTranscodeProfile($this->user);
        $channel = tvCreateChannel($this->user, $this->playlist, [
            'name' => 'TxFail Channel',
            'stream_profile_id' => $profile->id,
        ]);

        $this->mock(M3uProxyService::class, function ($mock) {
            $mock->shouldReceive('getChannelUrl')
                ->once()
                ->andThrow(new Exception('Proxy connection refused'));
        });

        $response = $this->postJson($this->resolveUrl, [
            'type' => 'live',
            'stream_id' => $channel->id,
            'client_capabilities' => tvIncompatibleCaps(),
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'unsupported')
            ->assertJsonPath('url', null)
            ->assertJsonPath('reason', 'Transcoding unavailable')
            ->assertJsonStructure(['mode', 'url', 'reason', 'source']);
    });

    it('returns unsupported with null url when getEpisodeUrl throws', function () {
        $profile = tvCreateTranscodeProfile($this->user);
        $this->playlist->update(['vod_stream_profile_id' => $profile->id]);

        $series = tvCreateSeries($this->user, $this->playlist, 'TxFail Series');
        $season = tvCreateSeason($this->user, $this->playlist, $series, 'Season Tx');
        $episode = tvCreateEpisode($this->user, $this->playlist, $series, $season, 'TxFail Episode', [
            'stream_stats' => json_encode(tvMakeStats(format: 'matroska')),
        ]);

        $this->mock(M3uProxyService::class, function ($mock) {
            $mock->shouldReceive('getEpisodeUrl')
                ->once()
                ->andThrow(new Exception('API timeout'));
        });

        $response = $this->postJson($this->resolveUrl, [
            'type' => 'series',
            'stream_id' => $episode->id,
            'client_capabilities' => tvIncompatibleCaps(),
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'unsupported')
            ->assertJsonPath('url', null)
            ->assertJsonPath('reason', 'Transcoding unavailable')
            ->assertJsonStructure(['mode', 'url', 'reason', 'source']);
    });
});

/*
|--------------------------------------------------------------------------
| adaptive profile resolution for episodes
|--------------------------------------------------------------------------
*/

describe('adaptive profile resolution for episodes', function () {
    beforeEach(function () {
        $this->withoutMiddleware(ThrottleRequestsWithRedis::class);
        $this->user = tvCreateUser('adaptepuser', ['permissions' => ['use_proxy']]);
        $this->playlist = tvCreatePlaylist($this->user, 'AdaptEp Playlist');
        tvCreateAuth($this->user, $this->playlist, 'adaptep_user', 'adaptep_pass');
        $this->seriesUrl = route('tv.stream.resolve.basic');
        $this->withBasicAuth('adaptep_user', 'adaptep_pass');
    });

    it('resolves adaptive profile on episode using stream stats', function () {
        $concrete = tvCreateTranscodeProfile($this->user, [
            'name' => 'concrete-720p',
            'format' => 'mkv',
            'args' => '-vf scale=-2:720 -c:v libx265 -c:a aac -f matroska',
        ]);
        $adaptive = StreamProfile::factory()->for($this->user)->create([
            'backend' => 'adaptive',
            'name' => 'adaptive-720p',
            'rules' => [
                ['conditions' => [['field' => 'video.height', 'op' => '<=', 'value' => 720]], 'stream_profile_id' => $concrete->id],
            ],
            'else_stream_profile_id' => null,
        ]);

        $this->playlist->update(['vod_stream_profile_id' => $adaptive->id]);
        $this->playlist->load('vodStreamProfile');

        $series = tvCreateSeries($this->user, $this->playlist, 'AdaptEp Series');
        $season = tvCreateSeason($this->user, $this->playlist, $series, 'Season AE');
        $episode = tvCreateEpisode($this->user, $this->playlist, $series, $season, 'AdaptEp Episode', [
            'stream_stats' => json_encode(tvMakeStats(['height' => 480], format: 'matroska')),
        ]);

        $mockUrl = 'https://proxy.test/hls/episode/adaptive.m3u8';
        $this->mock(M3uProxyService::class, function ($mock) use ($mockUrl, $concrete) {
            $mock->shouldReceive('getEpisodeUrl')
                ->once()
                ->withArgs(fn ($playlist, $episode, $profile) => $profile?->id === $concrete->id)
                ->andReturn($mockUrl);
        });

        $response = $this->postJson($this->seriesUrl, [
            'type' => 'series',
            'stream_id' => $episode->id,
            'client_capabilities' => tvIncompatibleCaps(['containers' => ['mkv']]),
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'transcode')
            ->assertJsonPath('url', $mockUrl);
    });

    it('returns unsupported when adaptive profile resolves to null on episode', function () {
        $adaptive = StreamProfile::factory()->for($this->user)->create([
            'backend' => 'adaptive',
            'name' => 'adaptive-no-match',
            'rules' => [
                ['conditions' => [['field' => 'video.height', 'op' => '>', 'value' => 1080]], 'stream_profile_id' => 99999],
            ],
            'else_stream_profile_id' => null,
        ]);

        $this->playlist->update(['vod_stream_profile_id' => $adaptive->id]);
        $this->playlist->load('vodStreamProfile');

        $series = tvCreateSeries($this->user, $this->playlist, 'AdaptEp Series 2');
        $season = tvCreateSeason($this->user, $this->playlist, $series, 'Season AE2');
        $episode = tvCreateEpisode($this->user, $this->playlist, $series, $season, 'AdaptEp Episode 2', [
            'stream_stats' => json_encode(tvMakeStats(['height' => 720], format: 'matroska')),
        ]);

        $response = $this->postJson($this->seriesUrl, [
            'type' => 'series',
            'stream_id' => $episode->id,
            'client_capabilities' => tvIncompatibleCaps(['containers' => ['mkv']]),
        ]);

        $response->assertOk()
            ->assertJsonPath('mode', 'unsupported')
            ->assertJsonPath('url', null);
    });
});
