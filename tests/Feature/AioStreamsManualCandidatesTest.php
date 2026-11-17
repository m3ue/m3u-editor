<?php

use App\Filament\Resources\MediaServerIntegrations\Pages\EditMediaServerIntegration;
use App\Filament\Resources\MediaServerIntegrations\RelationManagers\AioStreamsMoviesRelationManager;
use App\Filament\Resources\Series\Pages\EditSeries;
use App\Filament\Resources\Series\RelationManagers\EpisodesRelationManager;
use App\Jobs\ResolveAioStreamsChannel;
use App\Models\Channel;
use App\Models\ChannelFailover;
use App\Models\Episode;
use App\Models\EpisodeFailover;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\Series;
use App\Models\User;
use App\Services\AioStreamsCandidateService;
use App\Services\AioStreamsQualityParser;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function aioManualStream(string $name, string $filename): array
{
    return [
        'name' => $name,
        'description' => "{$name}\nEnglish",
        'url' => "https://debrid.test/{$filename}",
        'behaviorHints' => ['filename' => $filename],
    ];
}

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
        'manifest_url' => 'https://aiostreams.test/abc/manifest.json',
        'playlist_id' => $this->playlist->id,
    ]);
    $this->channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_custom' => true,
        'is_vod' => true,
        'aio_integration_id' => $this->integration->id,
        'aio_item_id' => 'tt1234567',
        'aio_type' => 'movie',
        'aio_resolution_status' => 'resolved',
    ]);

    $this->streams = [
        aioManualStream('Movie 2160p Voice Over', 'movie.2160p.vo.mkv'),
        aioManualStream('Movie 1080p Original', 'movie.1080p.orig.mkv'),
        aioManualStream('Movie 720p Original', 'movie.720p.orig.mkv'),
    ];
});

it('lets the user pick and order streams manually from the rescan action', function () {
    Http::fake(['aiostreams.test/abc/stream/movie/tt1234567.json*' => Http::response(['streams' => $this->streams])]);

    $component = Livewire::test(AioStreamsMoviesRelationManager::class, [
        'ownerRecord' => $this->integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])
        ->mountAction(TestAction::make('rescan')->table($this->channel))
        ->fillForm(['mode' => 'manual']);

    // Picking Manual alone must not fetch: the (slow) fetch runs on "Next", so
    // the submit button can't be clicked while it's still in flight.
    Http::assertNothingSent();
    expect($component->instance()->mountedActions[0]['data']['candidates'])->toBe([]);

    $component->goToNextWizardStep();
    Http::assertSentCount(1);

    $rows = $component->instance()->mountedActions[0]['data']['candidates'];
    expect($rows)->toHaveCount(3);

    // Drop the voice-over stream and put the 720p ahead of the 1080p.
    $byName = collect($rows)->mapWithKeys(fn ($row, $uuid) => [$row['name'] => [$uuid, $row]]);
    $reordered = [];
    foreach (['Movie 720p Original' => true, 'Movie 1080p Original' => true, 'Movie 2160p Voice Over' => false] as $name => $selected) {
        [$uuid, $row] = $byName[$name];
        $reordered[$uuid] = [...$row, 'selected' => $selected];
    }

    // Replace the whole array (what the Repeater's drag-reorder does) - fillForm()
    // writes leaf-by-leaf and would keep the original key order.
    $component->set('mountedActions.0.data.candidates', $reordered)
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified();

    $this->channel->refresh();
    $failoverClone = ChannelFailover::where('channel_id', $this->channel->id)->first()->channelFailover()->withoutGlobalScopes()->first();

    expect($this->channel->movie_data['aiostreams']['resolved_url'])->toBe('https://debrid.test/movie.720p.orig.mkv')
        ->and($this->channel->movie_data['aiostreams']['manual_keys'])->toHaveCount(2)
        ->and(ChannelFailover::where('channel_id', $this->channel->id)->count())->toBe(1)
        ->and($failoverClone->movie_data['aiostreams']['resolved_url'])->toBe('https://debrid.test/movie.1080p.orig.mkv')
        ->and($this->channel->aio_resolution_status)->toBe('resolved');
});

it('keeps a manual selection when the item is re-resolved automatically', function () {
    $service = app(AioStreamsCandidateService::class);
    $ranked = (new AioStreamsQualityParser)->rank($this->streams);
    $service->apply($this->channel, $service->selectByKeys($ranked, [AioStreamsCandidateService::streamKey($this->streams[1])]), [AioStreamsCandidateService::streamKey($this->streams[1])]);

    Http::fake(['aiostreams.test/abc/stream/movie/tt1234567.json*' => Http::response(['streams' => $this->streams])]);

    (new ResolveAioStreamsChannel($this->channel->id))->handle(new AioStreamsQualityParser);

    $this->channel->refresh();
    expect($this->channel->movie_data['aiostreams']['resolved_url'])->toBe('https://debrid.test/movie.1080p.orig.mkv')
        ->and($this->channel->movie_data['aiostreams']['manual_keys'])->toHaveCount(1)
        ->and(ChannelFailover::where('channel_id', $this->channel->id)->count())->toBe(0);
});

it('falls back to the automatic ranking when none of the manual picks are offered anymore', function () {
    $this->channel->update(['movie_data' => ['aiostreams' => ['manual_keys' => [sha1('gone')]]]]);

    Http::fake(['aiostreams.test/abc/stream/movie/tt1234567.json*' => Http::response(['streams' => $this->streams])]);

    (new ResolveAioStreamsChannel($this->channel->id))->handle(new AioStreamsQualityParser);

    $this->channel->refresh();
    expect($this->channel->movie_data['aiostreams'])->not->toHaveKey('manual_keys')
        ->and($this->channel->movie_data['aiostreams']['resolved_url'])->toBe('https://debrid.test/movie.2160p.vo.mkv')
        ->and(ChannelFailover::where('channel_id', $this->channel->id)->count())->toBe(2);
});

it('clears a manual selection when rescanning automatically', function () {
    Bus::fake();
    $this->channel->update(['movie_data' => ['aiostreams' => ['manual_keys' => ['abc']]]]);

    Livewire::test(AioStreamsMoviesRelationManager::class, [
        'ownerRecord' => $this->integration,
        'pageClass' => EditMediaServerIntegration::class,
    ])
        ->callAction(TestAction::make('rescan')->table($this->channel), ['mode' => 'automatic']);

    $this->channel->refresh();
    expect($this->channel->movie_data['aiostreams'])->not->toHaveKey('manual_keys')
        ->and($this->channel->aio_resolution_status)->toBe('pending');
    Bus::assertDispatched(ResolveAioStreamsChannel::class, fn ($job) => $job->channelId === $this->channel->id);
});

it('applies a manual selection to an AIOStreams episode', function () {
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
        'aio_item_id' => 'tt1234567:1:1',
        'aio_resolution_status' => 'resolved',
    ]);

    Http::fake(['aiostreams.test/abc/stream/series/*' => Http::response(['streams' => $this->streams])]);

    $service = app(AioStreamsCandidateService::class);
    $rows = $service->loadManualCandidates($episode);
    $keys = collect($rows)->pluck('key')->reverse()->values()->all();

    expect($service->applyManualSelection($episode, $keys))->toBe(3);

    $episode->refresh();
    expect($episode->info['aiostreams']['resolved_url'])->toBe('https://debrid.test/movie.720p.orig.mkv')
        ->and($episode->info['aiostreams']['manual_keys'])->toBe($keys)
        ->and(EpisodeFailover::where('episode_id', $episode->id)->count())->toBe(2);

    Livewire::test(EpisodesRelationManager::class, [
        'ownerRecord' => $series,
        'pageClass' => EditSeries::class,
    ])->assertActionVisible(TestAction::make('rescan')->table($episode));
});
