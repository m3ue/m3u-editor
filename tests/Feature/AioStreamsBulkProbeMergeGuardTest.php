<?php

use App\Filament\Actions\BulkModalActionGroup;
use App\Filament\Resources\Channels\ChannelResource;
use App\Filament\Resources\Series\Pages\ViewSeries;
use App\Filament\Resources\Series\RelationManagers\EpisodesRelationManager;
use App\Filament\Resources\Series\SeriesResource;
use App\Filament\Resources\Vods\VodResource;
use App\Jobs\ProbeStreamsChunk;
use App\Jobs\ProbeStreamsComplete;
use App\Models\Channel;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\Series;
use App\Models\User;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Bus;
use Livewire\Livewire;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
    ]);
});

/**
 * These bulk actions live inside a BulkModalActionGroup, which doesn't expose its
 * nested actions through Filament's normal Livewire::callTableBulkAction() resolution
 * (see ChannelBulkProbingActionsTest, which uses the same reflection technique for the
 * same reason). Extract the named BulkAction and invoke its closure directly instead.
 */
function findAndCallBulkAction(array $bulkActions, string $name, Collection $records): void
{
    $schemaProp = new ReflectionProperty(BulkModalActionGroup::class, 'schema');
    $childProp = new ReflectionProperty(Component::class, 'childComponents');

    $action = null;
    foreach ($bulkActions as $group) {
        if (! $group instanceof BulkModalActionGroup) {
            continue;
        }
        foreach ($schemaProp->getValue($group) as $component) {
            foreach ($childProp->getValue($component)['default'] ?? [] as $child) {
                if ($child instanceof BulkAction && $child->getName() === $name) {
                    $action = $child;
                    break 3;
                }
            }
        }
    }

    expect($action)->not->toBeNull("Bulk action [{$name}] not found.");

    $actionClosure = (new ReflectionProperty($action, 'action'))->getValue($action);
    app()->call($actionClosure, ['records' => $records, 'data' => []]);
}

it('does not let the VOD list bulk "Enable Probing" action turn on probing for AIOStreams-added movies', function () {
    $aioChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'is_vod' => true,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
        'probe_enabled' => false,
    ]);
    $normalChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'is_vod' => true,
        'probe_enabled' => false,
    ]);

    findAndCallBulkAction(VodResource::getTableBulkActions(), 'enable-probing', new Collection([$aioChannel, $normalChannel]));

    expect($aioChannel->fresh()->probe_enabled)->toBeFalse()
        ->and($normalChannel->fresh()->probe_enabled)->toBeTrue();
});

it('does not let the VOD list bulk "Enable Merge" action turn on merging for AIOStreams-added movies', function () {
    $aioChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'is_vod' => true,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
        'can_merge' => false,
    ]);
    $normalChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'is_vod' => true,
        'is_custom' => true,
        'can_merge' => false,
    ]);

    findAndCallBulkAction(VodResource::getTableBulkActions(), 'enable-merge', new Collection([$aioChannel, $normalChannel]));

    // can_merge isn't boolean-cast on the model, so compare loosely against the raw DB value.
    expect($aioChannel->fresh()->can_merge)->toBeFalsy()
        ->and($normalChannel->fresh()->can_merge)->toBeTruthy();
});

it('does not let the Episodes relation manager bulk "Enable Probing" action turn on probing for AIOStreams episodes', function () {
    $series = Series::factory()->create(['user_id' => $this->user->id]);
    $aioEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'series_id' => $series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt1:1:1',
        'probe_enabled' => false,
    ]);
    $normalEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'series_id' => $series->id,
        'probe_enabled' => false,
    ]);

    Livewire::test(EpisodesRelationManager::class, [
        'ownerRecord' => $series,
        'pageClass' => ViewSeries::class,
    ])
        ->callTableBulkAction('enable-probing', [$aioEpisode, $normalEpisode]);

    expect($aioEpisode->fresh()->probe_enabled)->toBeFalse()
        ->and($normalEpisode->fresh()->probe_enabled)->toBeTrue();
});

it('does not let the Series list bulk "Enable Probing" action turn on probing for AIOStreams episodes of the selected series', function () {
    $series = Series::factory()->create(['user_id' => $this->user->id]);
    $aioEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'series_id' => $series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt1:1:1',
        'probe_enabled' => false,
    ]);
    $normalEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'series_id' => $series->id,
        'probe_enabled' => false,
    ]);

    findAndCallBulkAction(SeriesResource::getTableBulkActions(), 'enable-probing', new Collection([$series]));

    expect($aioEpisode->fresh()->probe_enabled)->toBeFalse()
        ->and($normalEpisode->fresh()->probe_enabled)->toBeTrue();
});

it('excludes AIOStreams episodes from the Series list bulk "Probe Streams" action even if probe_enabled was somehow left true', function () {
    Bus::fake();

    $series = Series::factory()->create(['user_id' => $this->user->id]);
    $aioEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'series_id' => $series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt1:1:1',
        // Simulate a bug/bypass upstream that left probing "enabled" on an AIO episode.
        'probe_enabled' => true,
    ]);
    $normalEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'series_id' => $series->id,
        'probe_enabled' => true,
    ]);

    findAndCallBulkAction(SeriesResource::getTableBulkActions(), 'probe-streams', new Collection([$series]));

    Bus::assertChained([
        fn (ProbeStreamsChunk $job) => $job->episodeIds === [$normalEpisode->id],
        ProbeStreamsComplete::class,
    ]);
});

it('excludes AIOStreams episodes from the Series "Probe Episode Streams" row action even if probe_enabled was somehow left true', function () {
    Bus::fake();

    $series = Series::factory()->create(['user_id' => $this->user->id]);
    $aioEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'series_id' => $series->id,
        'is_custom' => true,
        'aio_item_id' => 'tt1:1:1',
        'probe_enabled' => true,
    ]);
    $normalEpisode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'series_id' => $series->id,
        'probe_enabled' => true,
    ]);

    $actionClosure = null;
    foreach (SeriesResource::getTableActions() as $item) {
        if ($item instanceof ActionGroup) {
            foreach ($item->getFlatActions() as $flat) {
                if ($flat->getName() === 'probe') {
                    $actionClosure = (new ReflectionProperty($flat, 'action'))->getValue($flat);
                    break 2;
                }
            }
        }
    }

    expect($actionClosure)->not->toBeNull();
    app()->call($actionClosure, ['record' => $series]);

    Bus::assertChained([
        fn (ProbeStreamsChunk $job) => $job->episodeIds === [$normalEpisode->id],
        ProbeStreamsComplete::class,
    ]);
});

it('does not let the Channels list bulk "Enable Probing" action turn on probing for AIOStreams-added channels', function () {
    $aioChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'aio_integration_id' => $this->integration->id,
        'probe_enabled' => false,
    ]);
    $normalChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'probe_enabled' => false,
    ]);

    findAndCallBulkAction(ChannelResource::getTableBulkActions(), 'enable-probing', new Collection([$aioChannel, $normalChannel]));

    expect($aioChannel->fresh()->probe_enabled)->toBeFalse()
        ->and($normalChannel->fresh()->probe_enabled)->toBeTrue();
});

it('does not let the Channels list bulk "Enable Merge" action turn on merging for AIOStreams-added channels', function () {
    $aioChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'is_custom' => true,
        'aio_integration_id' => $this->integration->id,
        'can_merge' => false,
    ]);
    $normalChannel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'is_custom' => true,
        'can_merge' => false,
    ]);

    findAndCallBulkAction(ChannelResource::getTableBulkActions(), 'enable-merge', new Collection([$aioChannel, $normalChannel]));

    // can_merge isn't boolean-cast on the model, so compare loosely against the raw DB value.
    expect($aioChannel->fresh()->can_merge)->toBeFalsy()
        ->and($normalChannel->fresh()->can_merge)->toBeTruthy();
});
