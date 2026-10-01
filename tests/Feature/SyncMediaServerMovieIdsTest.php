<?php

use App\Interfaces\MediaServer;
use App\Jobs\SyncMediaServer;
use App\Models\Channel;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use App\Services\MediaServerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * Minimal MediaServer mock for driving the protected syncMovie() method.
 * Named differently from the helpers in SyncMediaServerCastLogoTest.php,
 * since Pest loads every test file into one PHP process.
 */
function fakeMediaServerForMovieIds(): MediaServer
{
    $service = Mockery::mock(MediaServer::class);
    $service->shouldReceive('extractGenres')->andReturn(['Action']);
    $service->shouldReceive('getContainerExtension')->andReturn('mp4');
    $service->shouldReceive('getStreamUrl')->andReturn('http://server/stream.mp4');
    $service->shouldReceive('ticksToSeconds')->andReturn(7200);
    $service->shouldReceive('getImageUrl')->andReturnUsing(
        fn (string $id, string $type = 'Primary') => "http://server/img/{$id}/{$type}"
    );

    return $service;
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

it('writes tmdb_id, tvdb_id and imdb_id on a new movie from ProviderIds', function () {
    $movie = [
        'Id' => 'm1',
        'Name' => 'The Matrix',
        'ProviderIds' => ['Tmdb' => '603', 'Tvdb' => '1281', 'Imdb' => 'tt0133093'],
        'People' => [],
    ];

    (new ReflectionMethod($this->job, 'syncMovie'))->invoke(
        $this->job,
        $this->integration,
        $this->playlist,
        fakeMediaServerForMovieIds(),
        $movie,
    );

    $channel = Channel::where('playlist_id', $this->playlist->id)
        ->where('source_id', "media-server-{$this->integration->id}-m1")
        ->firstOrFail();

    expect($channel->tmdb_id)->toBe(603)
        ->and($channel->tvdb_id)->toBe(1281)
        ->and($channel->imdb_id)->toBe('tt0133093')
        ->and($channel->is_vod)->toBeTrue();
});

it('keeps existing ids when ProviderIds is empty (local re-sync case)', function () {
    // Simulate a channel whose ids were filled earlier by FetchTmdbIds
    $existing = Channel::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'source_id' => "media-server-{$this->integration->id}-m2",
        'name' => 'Local Movie',
        'title' => 'Local Movie',
        'url' => 'http://server/stream.mp4',
        'enabled' => true,
        'is_vod' => true,
        'import_batch_no' => 'batch-0',
        'tmdb_id' => 42,
        'tvdb_id' => 99,
        'imdb_id' => 'tt0000042',
    ]);

    $movie = [
        'Id' => 'm2',
        'Name' => 'Local Movie',
        // Local items carry no ProviderIds
        'People' => [],
    ];

    (new ReflectionMethod($this->job, 'syncMovie'))->invoke(
        $this->job,
        $this->integration,
        $this->playlist,
        fakeMediaServerForMovieIds(),
        $movie,
    );

    $existing->refresh();

    expect($existing->tmdb_id)->toBe(42)
        ->and($existing->tvdb_id)->toBe(99)
        ->and($existing->imdb_id)->toBe('tt0000042');
});

it('requests ProviderIds in the movie list Fields', function () {
    Http::preventStrayRequests();
    Http::fake([
        'http://10.0.0.2:8096/*' => Http::response(['Items' => [], 'TotalRecordCount' => 0]),
    ]);

    $service = MediaServerService::make($this->integration);
    $service->fetchMovies();

    Http::assertSent(function (Request $request): bool {
        return str_contains($request->url(), '/Items')
            && str_contains($request['Fields'] ?? '', 'ProviderIds');
    });
});
