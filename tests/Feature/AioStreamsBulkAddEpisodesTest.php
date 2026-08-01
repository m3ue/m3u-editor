<?php

use App\Livewire\AioStreamsBrowse;
use App\Models\Episode;
use App\Models\MediaServerIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    $this->user = User::factory()->create(['permissions' => ['use_integrations']]);
    $this->actingAs($this->user);

    $this->integration = MediaServerIntegration::create([
        'user_id' => $this->user->id,
        'name' => 'Test AIOStreams',
        'type' => 'aiostreams',
        'enabled' => true,
        'manifest_url' => 'https://aiostreams.test/manifest.json',
        'aiostreams_catalogs' => [
            ['id' => 'top-series', 'type' => 'series', 'name' => 'Top Series', 'searchable' => true],
        ],
        'aiostreams_enable_all_catalogs' => true,
    ]);

    Http::fake([
        '*/meta/series/tt2.json' => Http::response([
            'meta' => [
                'id' => 'tt2',
                'name' => 'Rick and Morty',
                'videos' => [
                    ['season' => 1, 'episode' => 1, 'title' => 'Pilot'],
                    ['season' => 1, 'episode' => 2, 'title' => 'Lawnmower Dog'],
                ],
            ],
        ]),
        '*/stream/series/*' => Http::response(['streams' => []]),
    ]);
});

it('selects all episodes in a season via the toggle, then bulk-adds them and clears the selection', function () {
    $component = Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'series', 'tt2')
        ->assertSet('selectedEpisodesCount', 0)
        ->call('toggleSelectAllForSeason', 1)
        ->assertSet('selectedEpisodesCount', 2);

    $component->call('addSelectedEpisodesToLibrary')
        ->assertSet('selectedEpisodesCount', 0)
        ->assertSet('selectedEpisodes', []);

    expect(Episode::where('is_custom', true)->count())->toBe(2)
        ->and(Episode::where('aio_item_id', 'tt2:1:1')->exists())->toBeTrue()
        ->and(Episode::where('aio_item_id', 'tt2:1:2')->exists())->toBeTrue();
});

it('toggling select-all again when everything is selected clears the selection', function () {
    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'series', 'tt2')
        ->call('toggleSelectAllForSeason', 1)
        ->assertSet('selectedEpisodesCount', 2)
        ->call('toggleSelectAllForSeason', 1)
        ->assertSet('selectedEpisodesCount', 0);
});

it('does not double-add an episode that was individually checked then included in select-all', function () {
    Livewire::test(AioStreamsBrowse::class, ['integrationId' => $this->integration->id])
        ->call('openDetail', 'series', 'tt2')
        ->call('toggleEpisodeSelected', 1, 1)
        ->assertSet('selectedEpisodesCount', 1)
        ->call('toggleSelectAllForSeason', 1)
        ->assertSet('selectedEpisodesCount', 2);
});
