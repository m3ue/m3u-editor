<?php

use App\Interfaces\MediaServer;
use App\Jobs\SyncMediaServer;
use App\Models\Channel;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Drive a fake MediaServer through the protected per-item sync methods so the
 * rich cast_list + clearlogo mapping is covered without a live server.
 */
function fakeMediaServer(): MediaServer
{
    $service = Mockery::mock(MediaServer::class);
    $service->shouldReceive('extractGenres')->andReturn(['Action']);
    $service->shouldReceive('getContainerExtension')->andReturn('mp4');
    $service->shouldReceive('getStreamUrl')->andReturn('http://server/stream.mp4');
    $service->shouldReceive('ticksToSeconds')->andReturn(7200);
    $service->shouldReceive('getImageUrl')->andReturnUsing(
        fn (string $id, string $type = 'Primary') => "http://server/img/{$id}/{$type}"
    );
    $service->shouldReceive('fetchSeriesDetails')->andReturnNull();
    $service->shouldReceive('fetchSeasons')->andReturn(collect());

    return $service;
}

function invokeSync(SyncMediaServer $job, string $method, array $args): void
{
    $ref = new ReflectionMethod($job, $method);
    $ref->invoke($job, ...$args);
}

beforeEach(function () {
    Queue::fake();
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->integration = MediaServerIntegration::create([
        'name' => 'Emby',
        'type' => 'emby',
        'host' => '10.0.0.2',
        'port' => 8096,
        'api_key' => 'k',
        'enabled' => true,
        'ssl' => false,
        'genre_handling' => 'primary',
        'import_movies' => true,
        'import_series' => true,
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
    ]);

    $this->job = new SyncMediaServer($this->integration->id);
    (new ReflectionProperty($this->job, 'batchNo'))->setValue($this->job, 'batch-1');
});

it('maps media server People to a null-id cast_list and pulls the Logo image on a movie', function () {
    invokeSync($this->job, 'syncMovie', [
        $this->integration,
        $this->playlist,
        fakeMediaServer(),
        [
            'Id' => 'm1',
            'Name' => 'The Matrix',
            'ImageTags' => ['Logo' => 'logo-tag'],
            'People' => [
                ['Id' => 'p1', 'Name' => 'Keanu Reeves', 'Type' => 'Actor', 'Role' => 'Neo', 'PrimaryImageTag' => 't1'],
                ['Id' => 'p2', 'Name' => 'Carrie-Anne Moss', 'Type' => 'Actor', 'Role' => 'Trinity'],
                ['Id' => 'd1', 'Name' => 'Lana Wachowski', 'Type' => 'Director'],
            ],
        ],
    ]);

    $channel = Channel::where('playlist_id', $this->playlist->id)->firstOrFail();

    // toEqual (not toBe): `info` is a jsonb column, and Postgres jsonb does not
    // preserve object key insertion order (it sorts keys by length then bytes),
    // so the round-tripped map key order is not a contract - only the member
    // list order and the values are.
    expect($channel->info['clearlogo'])->toBe('http://server/img/m1/Logo')
        ->and($channel->info['cast_list'])->toEqual([
            ['id' => null, 'name' => 'Keanu Reeves', 'character' => 'Neo', 'photo' => 'http://server/img/p1/Primary'],
            ['id' => null, 'name' => 'Carrie-Anne Moss', 'character' => 'Trinity', 'photo' => null],
        ]);
});

it('omits clearlogo when the movie has no Logo image tag', function () {
    invokeSync($this->job, 'syncMovie', [
        $this->integration,
        $this->playlist,
        fakeMediaServer(),
        [
            'Id' => 'm2',
            'Name' => 'No Logo Movie',
            'ImageTags' => ['Primary' => 'x'],
            'People' => [],
        ],
    ]);

    $channel = Channel::where('playlist_id', $this->playlist->id)->firstOrFail();

    expect($channel->info)->not->toHaveKey('clearlogo')
        ->and($channel->info)->not->toHaveKey('cast_list');
});

it('maps series People to a null-id cast_list and pulls the Logo image', function () {
    invokeSync($this->job, 'syncOneSeries', [
        $this->integration,
        $this->playlist,
        fakeMediaServer(),
        [
            'Id' => 's1',
            'Name' => 'Andor',
            'ImageTags' => ['Logo' => 'logo-tag'],
            'People' => [
                ['Id' => 'p9', 'Name' => 'Diego Luna', 'Type' => 'Actor', 'Role' => 'Cassian', 'PrimaryImageTag' => 'tt'],
            ],
        ],
    ]);

    $series = Series::where('playlist_id', $this->playlist->id)->firstOrFail();

    // toEqual (not toBe): see the note on the movie test above - `metadata` is a
    // jsonb column and does not preserve map key order.
    expect($series->metadata['clearlogo'])->toBe('http://server/img/s1/Logo')
        ->and($series->metadata['cast_list'])->toEqual([
            ['id' => null, 'name' => 'Diego Luna', 'character' => 'Cassian', 'photo' => 'http://server/img/p9/Primary'],
        ]);
});

it('keeps TMDB-owned fields on an enriched movie when a re-sync carries server values', function () {
    $tmdbCast = [['id' => 6384, 'name' => 'Keanu Reeves', 'character' => 'Neo', 'photo' => 'https://image.tmdb.org/k.jpg']];
    $movie = [
        'Id' => 'm1',
        'Name' => 'The Matrix',
        'Overview' => 'Server plot',
        'ImageTags' => ['Primary' => 'x', 'Logo' => 'logo-tag'],
        'People' => [
            ['Id' => 'p1', 'Name' => 'Keanu Reeves', 'Type' => 'Actor', 'Role' => 'Neo'],
            ['Id' => 'd1', 'Name' => 'Server Director', 'Type' => 'Director'],
        ],
    ];

    invokeSync($this->job, 'syncMovie', [$this->integration, $this->playlist, fakeMediaServer(), $movie]);

    $channel = Channel::where('playlist_id', $this->playlist->id)->firstOrFail();
    $channel->update(['info' => array_merge($channel->info, [
        'cast_list' => $tmdbCast,
        'clearlogo' => 'https://image.tmdb.org/logo.png',
        'related_tmdb' => [603, 604],
        'vote_count' => 25000,
        'backdrop_path' => ['https://image.tmdb.org/backdrop.jpg'],
        'cover_big' => 'https://image.tmdb.org/poster.jpg',
        'plot' => 'TMDB plot',
        'director' => 'Lana Wachowski, Lilly Wachowski',
        'rating' => 8.2,
    ])]);

    invokeSync($this->job, 'syncMovie', [$this->integration, $this->playlist, fakeMediaServer(), $movie]);

    $info = $channel->fresh()->info;

    expect($info['cast_list'])->toEqual($tmdbCast)
        ->and($info['clearlogo'])->toBe('https://image.tmdb.org/logo.png')
        ->and($info['related_tmdb'])->toBe([603, 604])
        ->and($info['vote_count'])->toBe(25000)
        ->and($info['backdrop_path'])->toBe(['https://image.tmdb.org/backdrop.jpg'])
        ->and($info['cover_big'])->toBe('https://image.tmdb.org/poster.jpg')
        ->and($info['plot'])->toBe('TMDB plot')
        ->and($info['director'])->toBe('Lana Wachowski, Lilly Wachowski')
        ->and($info['rating'])->toBe(8.2)
        ->and($info['cast'])->toBe('Keanu Reeves');
});

it('still refreshes the server cast_list on a movie TMDB has not enriched', function () {
    $movie = fn (string $role) => [
        'Id' => 'm1',
        'Name' => 'The Matrix',
        'ImageTags' => ['Primary' => 'x'],
        'People' => [['Id' => 'p1', 'Name' => 'Keanu Reeves', 'Type' => 'Actor', 'Role' => $role]],
    ];

    invokeSync($this->job, 'syncMovie', [$this->integration, $this->playlist, fakeMediaServer(), $movie('Neo')]);
    invokeSync($this->job, 'syncMovie', [$this->integration, $this->playlist, fakeMediaServer(), $movie('Thomas Anderson')]);

    $channel = Channel::where('playlist_id', $this->playlist->id)->firstOrFail();

    expect($channel->info['cast_list'][0]['character'])->toBe('Thomas Anderson');
});

it('keeps TMDB-owned fields on an enriched series when a re-sync carries server values', function () {
    $tmdbCast = [['id' => 1, 'name' => 'Diego Luna', 'character' => 'Cassian', 'photo' => 'https://image.tmdb.org/d.jpg']];
    $seriesData = [
        'Id' => 's1',
        'Name' => 'Andor',
        'Overview' => 'Server plot',
        'CommunityRating' => 7.0,
        'BackdropImageTags' => ['b1'],
        'ImageTags' => ['Logo' => 'logo-tag'],
        'People' => [['Id' => 'p9', 'Name' => 'Diego Luna', 'Type' => 'Actor', 'Role' => 'Cassian']],
    ];

    invokeSync($this->job, 'syncOneSeries', [$this->integration, $this->playlist, fakeMediaServer(), $seriesData]);

    $series = Series::where('playlist_id', $this->playlist->id)->firstOrFail();
    $series->update([
        'metadata' => array_merge($series->metadata, [
            'cast_list' => $tmdbCast,
            'clearlogo' => 'https://image.tmdb.org/logo.png',
            'related_tmdb' => [83867],
            'vote_count' => 1200,
        ]),
        'backdrop_path' => ['https://image.tmdb.org/backdrop.jpg'],
        'cover' => 'https://image.tmdb.org/poster.jpg',
        'plot' => 'TMDB plot',
        'cast' => 'Diego Luna, Stellan Skarsgard',
        'rating' => 8.4,
    ]);

    invokeSync($this->job, 'syncOneSeries', [$this->integration, $this->playlist, fakeMediaServer(), $seriesData]);

    $series = $series->fresh();
    $metadata = $series->metadata;

    expect($metadata['cast_list'])->toEqual($tmdbCast)
        ->and($metadata['related_tmdb'])->toBe([83867])
        ->and($metadata['vote_count'])->toBe(1200)
        ->and($metadata['clearlogo'])->toBe('https://image.tmdb.org/logo.png')
        ->and($metadata['media_server_id'])->toBe('s1')
        ->and($series->backdrop_path)->toBe(['https://image.tmdb.org/backdrop.jpg'])
        ->and($series->cover)->toBe('https://image.tmdb.org/poster.jpg')
        ->and($series->plot)->toBe('TMDB plot')
        ->and($series->cast)->toBe('Diego Luna, Stellan Skarsgard')
        ->and((float) $series->rating)->toBe(8.4);
});

it('keeps a TMDB clearlogo and cast_list on a series when the server sends none', function () {
    $seriesData = ['Id' => 's2', 'Name' => 'Local Show', 'ImageTags' => [], 'People' => []];

    invokeSync($this->job, 'syncOneSeries', [$this->integration, $this->playlist, fakeMediaServer(), $seriesData]);

    $series = Series::where('playlist_id', $this->playlist->id)->firstOrFail();
    $series->update(['metadata' => array_merge($series->metadata, [
        'cast_list' => [['id' => 7, 'name' => 'Someone', 'character' => 'Lead', 'photo' => null]],
        'clearlogo' => 'https://image.tmdb.org/logo.png',
    ])]);

    invokeSync($this->job, 'syncOneSeries', [$this->integration, $this->playlist, fakeMediaServer(), $seriesData]);

    $metadata = $series->fresh()->metadata;

    expect($metadata['clearlogo'])->toBe('https://image.tmdb.org/logo.png')
        ->and($metadata['cast_list'][0]['id'])->toBe(7);
});

it('still refreshes server values on a series TMDB has not enriched', function () {
    $seriesData = fn (string $plot) => ['Id' => 's3', 'Name' => 'Show', 'Overview' => $plot, 'ImageTags' => [], 'People' => []];

    invokeSync($this->job, 'syncOneSeries', [$this->integration, $this->playlist, fakeMediaServer(), $seriesData('First plot')]);
    invokeSync($this->job, 'syncOneSeries', [$this->integration, $this->playlist, fakeMediaServer(), $seriesData('Second plot')]);

    expect(Series::where('playlist_id', $this->playlist->id)->firstOrFail()->plot)->toBe('Second plot');
});
