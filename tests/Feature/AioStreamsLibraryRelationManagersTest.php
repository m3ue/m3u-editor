<?php

use App\Filament\Resources\MediaServerIntegrations\Pages\EditMediaServerIntegration;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\AioStreamsMoviesRelationManager;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\AioStreamsSeriesRelationManager;
use App\Jobs\ResolveAioStreamsChannel;
use App\Jobs\ResolveAioStreamsEpisode;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);

    $this->playlist = Playlist::factory()->create(['user_id' => $this->user->id]);
    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
        'playlist_id' => $this->playlist->id,
    ]);
});

it('lists AIOStreams-added movies with their resolution status', function () {
    $resolved = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'is_custom' => true,
        'title' => 'A Resolved Movie',
        'aio_integration_id' => $this->integration->id,
        'aio_resolution_status' => 'resolved',
    ]);

    $failed = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'is_custom' => true,
        'title' => 'A Failed Movie',
        'aio_integration_id' => $this->integration->id,
        'aio_resolution_status' => 'failed',
    ]);

    // Not custom / not this integration's — must not appear.
    Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'is_custom' => false,
        'title' => 'A Synced Movie',
    ]);

    Livewire::test(AioStreamsMoviesRelationManager::class, [
        'ownerRecord' => $this->integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$resolved, $failed])
        ->assertCanNotSeeTableRecords(Channel::where('title', 'A Synced Movie')->get());
});

it('rescans a movie from the relation manager, even if already resolved', function () {
    Bus::fake();

    $resolved = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
        'aio_resolution_status' => 'resolved',
    ]);

    Livewire::test(AioStreamsMoviesRelationManager::class, [
        'ownerRecord' => $this->integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])
        ->callTableAction('rescan', $resolved);

    Bus::assertDispatched(ResolveAioStreamsChannel::class, fn ($job) => $job->channelId === $resolved->id);
    expect($resolved->fresh()->aio_resolution_status)->toBe('pending');
});

it('removes an AIOStreams movie from the relation manager', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
        'aio_resolution_status' => 'resolved',
    ]);

    Livewire::test(AioStreamsMoviesRelationManager::class, [
        'ownerRecord' => $this->integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])
        ->callTableAction('delete', $channel);

    expect(Channel::find($channel->id))->toBeNull();
});

it('lists AIOStreams-added series with aggregate episode resolution counts', function () {
    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
    ]);

    Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt1:1:1',
        'aio_resolution_status' => 'resolved',
    ]);
    Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt1:1:2',
        'aio_resolution_status' => 'failed',
    ]);

    Livewire::test(AioStreamsSeriesRelationManager::class, [
        'ownerRecord' => $this->integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])
        ->assertOk()
        ->assertCanSeeTableRecords([$series]);

    expect($series->fresh()->episodes()->count())->toBe(2);
});

it('rescans every aired episode for an AIOStreams series from the relation manager, but skips unaired ones', function () {
    Bus::fake();

    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
    ]);

    $failedEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt1:1:1',
        'aio_resolution_status' => 'failed',
    ]);
    $resolvedEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt1:1:2',
        'aio_resolution_status' => 'resolved',
    ]);
    $unairedEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt1:1:3',
        'aio_resolution_status' => 'scheduled',
    ]);

    Livewire::test(AioStreamsSeriesRelationManager::class, [
        'ownerRecord' => $this->integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])
        ->callTableAction('rescan', $series);

    Bus::assertDispatched(ResolveAioStreamsEpisode::class, fn ($job) => $job->episodeId === $failedEpisode->id);
    Bus::assertDispatched(ResolveAioStreamsEpisode::class, fn ($job) => $job->episodeId === $resolvedEpisode->id);
    Bus::assertNotDispatched(ResolveAioStreamsEpisode::class, fn ($job) => $job->episodeId === $unairedEpisode->id);
    expect($failedEpisode->fresh()->aio_resolution_status)->toBe('pending')
        ->and($resolvedEpisode->fresh()->aio_resolution_status)->toBe('pending')
        ->and($unairedEpisode->fresh()->aio_resolution_status)->toBe('scheduled');
});

it('removing an AIOStreams series cascades to its episodes', function () {
    $series = Series::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
    ]);
    $episode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt1:1:1',
    ]);

    Livewire::test(AioStreamsSeriesRelationManager::class, [
        'ownerRecord' => $this->integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])
        ->callTableAction('delete', $series);

    expect(Series::find($series->id))->toBeNull();
    expect(Episode::find($episode->id))->toBeNull();
});
