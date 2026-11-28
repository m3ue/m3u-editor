<?php

use App\Interfaces\MediaServer;
use App\Jobs\SyncMediaServer;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

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
});

/**
 * MediaServer mock whose library lists three items and which deletes the
 * integration (and, through its deleting hook, the playlist) while the
 * first item is being synced.
 */
function deletedTargetFakeMediaServer(MediaServerIntegration $integration, string $fetchMethod): MediaServer
{
    $items = collect([
        ['Id' => 'a', 'Name' => 'First', 'People' => []],
        ['Id' => 'b', 'Name' => 'Second', 'People' => []],
        ['Id' => 'c', 'Name' => 'Third', 'People' => []],
    ]);

    $service = Mockery::mock(MediaServer::class);
    $service->shouldReceive($fetchMethod)->andReturn($items);
    $service->shouldReceive('extractGenres')->once()->andReturnUsing(function () use ($integration) {
        $integration->delete();

        return ['Action'];
    });
    $service->shouldIgnoreMissing();

    return $service;
}

it('stops syncing movies once the integration is deleted mid-sync', function () {
    $service = deletedTargetFakeMediaServer($this->integration, 'fetchMovies');

    (new ReflectionMethod($this->job, 'syncMovies'))
        ->invoke($this->job, $this->integration, $this->playlist, $service);

    $stats = (new ReflectionProperty($this->job, 'stats'))->getValue($this->job);

    expect(Playlist::whereKey($this->playlist->id)->exists())->toBeFalse()
        ->and((new ReflectionProperty($this->job, 'syncTargetDeleted'))->getValue($this->job))->toBeTrue()
        ->and(count($stats['errors']))->toBeLessThanOrEqual(1);
});

it('stops syncing series once the integration is deleted mid-sync', function () {
    $service = deletedTargetFakeMediaServer($this->integration, 'fetchSeries');

    (new ReflectionMethod($this->job, 'syncSeries'))
        ->invoke($this->job, $this->integration, $this->playlist, $service);

    $stats = (new ReflectionProperty($this->job, 'stats'))->getValue($this->job);

    expect((new ReflectionProperty($this->job, 'syncTargetDeleted'))->getValue($this->job))->toBeTrue()
        ->and(count($stats['errors']))->toBeLessThanOrEqual(1);
});

it('keeps syncing when the integration and playlist still exist', function () {
    expect((new ReflectionMethod($this->job, 'syncTargetWasDeleted'))->invoke($this->job, $this->playlist))
        ->toBeFalse();
});
