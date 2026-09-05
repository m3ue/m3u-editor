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
