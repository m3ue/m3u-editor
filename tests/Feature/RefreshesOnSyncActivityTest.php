<?php

use App\Enums\Status;
use App\Filament\Resources\ChannelScrubbers\Pages\ListChannelScrubbers;
use App\Filament\Resources\EpgMaps\Pages\ListEpgMaps;
use App\Filament\Resources\EpgMaps\Pages\ViewEpgMap;
use App\Filament\Resources\Epgs\Pages\ListEpgs;
use App\Filament\Resources\MediaServerIntegrations\Pages\ListMediaServerIntegrations;
use App\Filament\Resources\MergedEpgs\Pages\ListMergedEpgs;
use App\Filament\Resources\Playlists\Pages\ListPlaylists;
use App\Models\ChannelScrubber;
use App\Models\Epg;
use App\Models\EpgMap;
use App\Models\MediaServerIntegration;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

beforeEach(function () {
    Bus::fake();
    Http::preventStrayRequests();

    $this->user = User::factory()->create(['permissions' => ['use_integrations', 'use_scrubber']]);
    $this->actingAs($this->user);
});

/**
 * Create a record of the page's resource for the given user, with an "idle" or
 * "active" sync status expressed in that model's own status vocabulary.
 */
function syncActivityMakeRecord(string $page, User $user, bool $active): Model
{
    $status = $active ? Status::Processing : Status::Completed;

    return match ($page) {
        ListPlaylists::class => Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create(['status' => $status])),
        ListEpgs::class => Epg::withoutEvents(fn () => Epg::factory()->for($user)->create(['status' => $status, 'is_merged' => false])),
        ListMergedEpgs::class => Epg::withoutEvents(fn () => Epg::factory()->for($user)->create(['status' => $status, 'is_merged' => true])),
        ListEpgMaps::class => EpgMap::withoutEvents(fn () => EpgMap::factory()->create([
            'user_id' => $user->id,
            'epg_id' => Epg::withoutEvents(fn () => Epg::factory()->for($user)->create())->id,
            'playlist_id' => Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create())->id,
            'status' => $status,
        ])),
        ListChannelScrubbers::class => ChannelScrubber::withoutEvents(fn () => ChannelScrubber::create([
            'name' => 'Scrubber',
            'user_id' => $user->id,
            'playlist_id' => Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create())->id,
            'status' => $status,
        ])),
        ListMediaServerIntegrations::class => MediaServerIntegration::withoutEvents(fn () => MediaServerIntegration::factory()->for($user)->create([
            'type' => 'jellyfin',
            'status' => $active ? 'processing' : 'completed',
        ])),
    };
}

function syncActivityActiveStatus(Model $record): string|Status
{
    return $record instanceof MediaServerIntegration ? 'processing' : Status::Processing;
}

dataset('sync list pages', [
    'playlists' => ListPlaylists::class,
    'epgs' => ListEpgs::class,
    'merged epgs' => ListMergedEpgs::class,
    'epg maps' => ListEpgMaps::class,
    'channel scrubbers' => ListChannelScrubbers::class,
    'media server integrations' => ListMediaServerIntegrations::class,
]);

it('polls slowly when nothing is syncing', function (string $page) {
    syncActivityMakeRecord($page, $this->user, active: false);

    Livewire::test($page)
        ->assertSet('hasActiveSyncs', false)
        ->assertSeeHtml('wire:poll.10s="checkSyncActivity"');
})->with('sync list pages');

it('polls quickly while a record is syncing', function (string $page) {
    syncActivityMakeRecord($page, $this->user, active: true);

    Livewire::test($page)
        ->assertSet('hasActiveSyncs', true)
        ->assertSeeHtml('wire:poll.3s="checkSyncActivity"');
})->with('sync list pages');

it('skips the render when nothing changed', function (string $page) {
    syncActivityMakeRecord($page, $this->user, active: false);

    $component = Livewire::test($page)->call('checkSyncActivity');

    expect($component->effects)->not->toHaveKey('html');
})->with('sync list pages');

it('re-renders and speeds up when a sync starts in the background', function (string $page) {
    $record = syncActivityMakeRecord($page, $this->user, active: false);

    $component = Livewire::test($page);

    $this->travel(5)->seconds();
    $record->newQuery()->whereKey($record->getKey())->update(['status' => syncActivityActiveStatus($record)]);

    $component->call('checkSyncActivity');

    expect($component->effects)->toHaveKey('html');
    $component
        ->assertSet('hasActiveSyncs', true)
        ->assertSeeHtml('wire:poll.3s="checkSyncActivity"');
})->with('sync list pages');

it('re-renders on progress updates while a sync is running', function (string $page) {
    $record = syncActivityMakeRecord($page, $this->user, active: true);
    $progressColumn = $record instanceof MediaServerIntegration ? 'movie_progress' : 'progress';

    $component = Livewire::test($page);

    $this->travel(5)->seconds();
    $record->update([$progressColumn => 42]);

    $component->call('checkSyncActivity');

    expect($component->effects)->toHaveKey('html');
})->with('sync list pages');

it('ignores sync activity belonging to other users', function (string $page) {
    syncActivityMakeRecord($page, $this->user, active: false);

    $component = Livewire::test($page);

    syncActivityMakeRecord($page, User::factory()->create(), active: true);

    $component->call('checkSyncActivity');

    expect($component->effects)->not->toHaveKey('html');
    $component->assertSet('hasActiveSyncs', false);
})->with('sync list pages');

it('keeps polling through the EPG import to cache generation status hand-off', function () {
    $epg = syncActivityMakeRecord(ListEpgs::class, $this->user, active: true);

    $component = Livewire::test(ListEpgs::class)
        ->assertSeeHtml('wire:poll.3s="checkSyncActivity"');

    // ProcessEpgImportComplete marks the EPG completed before SyncListener
    // flips it back to processing for cache generation.
    $this->travel(5)->seconds();
    $epg->update(['status' => Status::Completed]);
    $component->call('checkSyncActivity')
        ->assertSeeHtml('wire:poll.10s="checkSyncActivity"');

    $this->travel(5)->seconds();
    $epg->update(['status' => Status::Processing]);
    $component->call('checkSyncActivity')
        ->assertSeeHtml('wire:poll.3s="checkSyncActivity"');
});

it('does not treat an idle media server integration as syncing', function () {
    MediaServerIntegration::withoutEvents(fn () => MediaServerIntegration::factory()->for($this->user)->create([
        'type' => 'jellyfin',
        'status' => 'idle',
    ]));

    Livewire::test(ListMediaServerIntegrations::class)
        ->assertSet('hasActiveSyncs', false)
        ->assertSeeHtml('wire:poll.10s="checkSyncActivity"');
});

it('polls the EPG map view page quickly while a mapping run is active', function (bool $active, string $interval) {
    $map = syncActivityMakeRecord(ListEpgMaps::class, $this->user, active: $active);

    Livewire::test(ViewEpgMap::class, ['record' => $map->getKey()])
        ->assertSeeHtml("wire:poll.{$interval}");
})->with([
    'active' => [true, '3s'],
    'idle' => [false, '10s'],
]);
