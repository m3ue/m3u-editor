<?php

use App\Filament\Resources\PlaylistViewers\Pages\ViewPlaylistViewer;
use App\Filament\Resources\PlaylistViewers\RelationManagers\WatchProgressRelationManager;
use App\Filament\Resources\Vods\Pages\ListVod;
use App\Models\Channel;
use App\Models\DvrRecording;
use App\Models\DvrSetting;
use App\Models\Episode;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\PlaylistViewer;
use App\Models\Series;
use App\Models\User;
use App\Models\ViewerWatchProgress;
use App\Services\ManualWatchProgressService;
use App\Services\TmdbService;
use App\Services\WatchProgressLinker;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Mockery\MockInterface;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->playlistAuth = PlaylistAuth::create([
        'name' => 'Test',
        'username' => 'testuser',
        'password' => 'testpass',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($this->playlistAuth);

    $this->viewer = PlaylistViewer::create([
        'ulid' => (string) Str::ulid(),
        'name' => 'admin',
        'is_admin' => false,
        'playlist_auth_id' => $this->playlistAuth->id,
        'viewerable_type' => $this->playlist->getMorphClass(),
        'viewerable_id' => $this->playlist->id,
    ]);

    $this->mock(TmdbService::class, function (MockInterface $mock): void {
        $mock->allows('isConfigured')->andReturn(true);
        $mock->allows('getMovieDetails')->with(550)->andReturn([
            'tmdb_id' => 550,
            'title' => 'Fight Club',
            'overview' => 'An insomniac office worker...',
            'poster_url' => 'https://image.tmdb.org/t/p/w500/fight-club.jpg',
            'backdrop_url' => 'https://image.tmdb.org/t/p/w1280/fight-club-bd.jpg',
            'release_date' => '1999-10-15',
            'vote_average' => 8.44,
            'runtime' => 139,
        ]);
        $mock->allows('searchMovieManual')->andReturn([
            ['id' => 550, 'title' => 'Fight Club', 'name' => 'Fight Club', 'year' => '1999', 'overview' => 'An insomniac office worker...', 'poster_path' => '/fight-club.jpg', 'vote_average' => 8.4],
            ['id' => 551, 'title' => 'Fight Club 2', 'name' => 'Fight Club 2', 'year' => '2030', 'overview' => '', 'poster_path' => null, 'vote_average' => null],
        ]);
        $mock->allows('getTvSeriesDetails')->with(1399)->andReturn([
            'tmdb_id' => 1399,
            'name' => 'Game of Thrones',
            'overview' => 'Seven noble families...',
            'poster_url' => 'https://image.tmdb.org/t/p/w500/got.jpg',
            'backdrop_url' => null,
            'first_air_date' => '2011-04-17',
            'vote_average' => 8.4,
        ]);
        $mock->allows('getSeasonDetails')->with(1399, 1)->andReturn([
            'season_number' => 1,
            'episodes' => [
                ['tmdb_id' => 63056, 'episode_number' => 1, 'name' => 'Winter Is Coming', 'runtime' => 62],
                ['tmdb_id' => 63057, 'episode_number' => 2, 'name' => 'The Kingsroad', 'runtime' => 56],
            ],
        ]);
    });

    $this->service = app(ManualWatchProgressService::class);
});

function recentlyWatchedFor(PlaylistViewer $viewer)
{
    $query = http_build_query([
        'username' => 'testuser',
        'password' => 'testpass',
        'action' => 'get_recently_watched',
        'viewer_id' => $viewer->ulid,
    ]);

    return test()->getJson(route('xtream.api.player').'?'.$query);
}

it('links a manually added movie to the library channel with the same tmdb id', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'tmdb_id' => 550,
        'info' => ['duration_secs' => 8340],
    ]);

    $progress = $this->service->record($this->viewer, 'movie', 550, null, null, 600, null, false);

    expect($progress->stream_id)->toBe($channel->id)
        ->and($progress->tmdb_id)->toBe(550)
        ->and($progress->duration_seconds)->toBe(8340) // library duration wins over TMDB runtime
        ->and($progress->title)->toBeNull()
        ->and($progress->isUnlinked())->toBeFalse();
});

it('stores a movie that is not in the library as unlinked with TMDB metadata', function () {
    $progress = $this->service->record($this->viewer, 'movie', 550, null, null, 600, null, false);

    expect($progress->stream_id)->toBeNull()
        ->and($progress->isUnlinked())->toBeTrue()
        ->and($progress->tmdb_id)->toBe(550)
        ->and($progress->title)->toBe('Fight Club')
        ->and($progress->year)->toBe('1999')
        ->and($progress->rating)->toBe('8.4')
        ->and($progress->thumbnail_url)->toBe('https://image.tmdb.org/t/p/w500/fight-club.jpg')
        ->and($progress->duration_seconds)->toBe(139 * 60)
        ->and($progress->content_title)->toBe('Fight Club');

    // Re-adding the same unlinked title updates the row instead of duplicating it.
    $this->service->record($this->viewer, 'movie', 550, null, null, 1200, null, true);

    expect(ViewerWatchProgress::count())->toBe(1)
        ->and($progress->fresh()->position_seconds)->toBe(1200);
});

it('links a manually added episode by series tmdb id and season/episode when episodes lack their own tmdb id', function () {
    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'tmdb_id' => 1399]);
    $episode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'season' => 1,
        'episode_num' => 2,
        'tmdb_id' => null,
    ]);

    $progress = $this->service->record($this->viewer, 'tv', 1399, 1, 2, 300, 3360, false);

    expect($progress->content_type)->toBe('episode')
        ->and($progress->stream_id)->toBe($episode->id)
        ->and($progress->series_id)->toBe($series->id)
        ->and($progress->season_number)->toBe(1)
        ->and($progress->episode_number)->toBe(2)
        ->and($progress->tmdb_id)->toBe(63057);
});

it('keeps unlinked rows on relink sweeps until the content arrives, then relinks and fills series fields', function () {
    $progress = $this->service->record($this->viewer, 'tv', 1399, 1, 1, 300, null, false);
    expect($progress->isUnlinked())->toBeTrue()
        ->and($progress->episode_title)->toBe('Winter Is Coming');

    $linker = app(WatchProgressLinker::class);

    expect($linker->preview()['items'])->toBe([]);
    expect($linker->pruneOrphaned())->toMatchArray(['relinked' => 0, 'deleted' => 0]);
    expect($progress->fresh())->not->toBeNull();

    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $episode = Episode::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'series_id' => $series->id,
        'season' => 1,
        'episode_num' => 1,
        'tmdb_id' => 63056,
    ]);

    expect($linker->pruneOrphaned())->toMatchArray(['relinked' => 1, 'deleted' => 0]);

    $progress->refresh();
    expect($progress->stream_id)->toBe($episode->id)
        ->and($progress->series_id)->toBe($series->id);
});

it('deletes an unlinked row on relink when the viewer already tracks that content, instead of hitting the unique index', function () {
    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'tmdb_id' => 550,
    ]);
    $tracked = ViewerWatchProgress::create([
        'playlist_viewer_id' => $this->viewer->id,
        'content_type' => 'vod',
        'stream_id' => $channel->id,
        'tmdb_id' => 550,
        'position_seconds' => 50,
        'last_watched_at' => now(),
    ]);
    $unlinked = ViewerWatchProgress::create([
        'playlist_viewer_id' => $this->viewer->id,
        'content_type' => 'vod',
        'stream_id' => null,
        'tmdb_id' => 550,
        'title' => 'Fight Club',
        'position_seconds' => 900,
        'last_watched_at' => now()->subDay(),
    ]);

    recentlyWatchedFor($this->viewer)->assertOk()->assertJsonCount(1);

    expect(app(WatchProgressLinker::class)->pruneOrphaned())->toMatchArray(['relinked' => 0, 'deleted' => 1]);
    expect($unlinked->fresh())->toBeNull()
        ->and($tracked->fresh())->not->toBeNull();
});

it('hides unlinked rows from get_recently_watched until they relink on read', function () {
    $this->service->record($this->viewer, 'movie', 550, null, null, 600, null, false);

    recentlyWatchedFor($this->viewer)->assertOk()->assertExactJson([]);

    $channel = Channel::factory()->create([
        'user_id' => $this->user->id,
        'playlist_id' => $this->playlist->id,
        'is_vod' => true,
        'tmdb_id' => 550,
    ]);

    $response = recentlyWatchedFor($this->viewer)->assertOk()->assertJsonCount(1);
    expect($response->json('0.stream_id'))->toBe($channel->id);
});

it('adds, edits and bulk-marks progress from the Playlist Viewer watch history', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(WatchProgressRelationManager::class, [
        'ownerRecord' => $this->viewer,
        'pageClass' => ViewPlaylistViewer::class,
    ])->set('activeTab', 'vod');

    $component->callAction(TestAction::make('addProgress')->table(), [
        'media_type' => 'movie',
        'tmdb_id' => 550,
        'position' => '1:02:03',
        'duration' => '2:19:00',
        'completed' => false,
    ])->assertHasNoFormErrors()->assertNotified();

    $progress = ViewerWatchProgress::sole();
    expect($progress->isUnlinked())->toBeTrue()
        ->and($progress->position_seconds)->toBe(3723)
        ->and($progress->duration_seconds)->toBe(8340);

    $component->callAction(TestAction::make('editProgress')->table($progress), [
        'position' => '90', // plain number = minutes
        'duration' => '2:19:00',
        'completed' => false,
    ])->assertHasNoFormErrors();

    expect($progress->fresh()->position_seconds)->toBe(5400);

    $component->callTableBulkAction('markWatched', [$progress]);

    expect($progress->fresh())
        ->completed->toBeTrue()
        ->position_seconds->toBe(8340);

    $component->callTableBulkAction('markUnwatched', [$progress]);

    expect($progress->fresh())
        ->completed->toBeFalse()
        ->position_seconds->toBe(0);

    $component->callAction(TestAction::make('editProgress')->table($progress), [
        'position' => 'not a time',
        'duration' => null,
        'completed' => false,
    ])->assertHasFormErrors(['position']);
});

it('renders every watch history tab with linked and unlinked rows', function () {
    $this->actingAs($this->user);

    $channel = Channel::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'is_vod' => true, 'tmdb_id' => 550]);
    $series = Series::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id]);
    $episode = Episode::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'series_id' => $series->id, 'season' => 1, 'episode_num' => 1]);

    $rows = [
        'live' => [ViewerWatchProgress::create(['playlist_viewer_id' => $this->viewer->id, 'content_type' => 'live', 'stream_id' => $channel->id, 'last_watched_at' => now()])],
        'vod' => [
            $this->service->record($this->viewer, 'movie', 550, null, null, 600, 8340, false),
            ViewerWatchProgress::create(['playlist_viewer_id' => $this->viewer->id, 'content_type' => 'vod', 'stream_id' => null, 'tmdb_id' => 13, 'title' => 'Forrest Gump', 'last_watched_at' => now()]),
        ],
        'episode' => [
            ViewerWatchProgress::create(['playlist_viewer_id' => $this->viewer->id, 'content_type' => 'episode', 'stream_id' => $episode->id, 'series_id' => $series->id, 'position_seconds' => 120, 'duration_seconds' => 2400, 'last_watched_at' => now()]),
            $this->service->record($this->viewer, 'tv', 1399, 1, 2, 300, null, false),
        ],
        'dvr_recording' => [ViewerWatchProgress::create([
            'playlist_viewer_id' => $this->viewer->id,
            'content_type' => 'dvr_recording',
            'stream_id' => DvrRecording::factory()
                ->for(DvrSetting::factory()->for($this->user)->for($this->playlist)->create(), 'dvrSetting')
                ->for($this->user)
                ->create(['epg_programme_data' => ['icon' => 'https://example.com/show-icon.jpg']])->id,
            'last_watched_at' => now(),
        ])],
        'aiostreams' => [ViewerWatchProgress::create(['playlist_viewer_id' => $this->viewer->id, 'content_type' => 'aiostreams', 'aio_item_id' => 'tt0137523', 'title' => 'Fight Club', 'last_watched_at' => now()])],
    ];

    foreach ($rows as $tab => $records) {
        $component = Livewire::test(WatchProgressRelationManager::class, [
            'ownerRecord' => $this->viewer,
            'pageClass' => ViewPlaylistViewer::class,
        ])
            ->set('activeTab', $tab)
            ->call('loadTable')
            ->assertCanSeeTableRecords($records);

        if ($tab === 'vod') {
            // Art renders at its natural aspect ratio inside a bounding box (no crop).
            $component->assertSeeHtml('max-height:210px')->assertSeeHtml('height: auto; width: auto');
        }

        if ($tab === 'dvr_recording') {
            // No VOD episode/channel yet, so the EPG programme icon is used.
            $component->assertSeeHtml('https://example.com/show-icon.jpg');
        }

        if ($tab === 'aiostreams') {
            // IMDb-keyed AIO rows use the Metahub poster, not the stored still.
            $component->assertSeeHtml('https://images.metahub.space/poster/medium/tt0137523/img');
        }

        if ($tab === 'episode') {
            // Unlinked episode row renders its stored TMDB title + S/E.
            $component->assertSee('Game of Thrones')->assertSee('S01E02');
        }
    }
});

it('restores the active watch history tab from the query string', function () {
    $this->actingAs($this->user);

    $vod = ViewerWatchProgress::create(['playlist_viewer_id' => $this->viewer->id, 'content_type' => 'vod', 'stream_id' => null, 'tmdb_id' => 13, 'title' => 'Forrest Gump', 'last_watched_at' => now()]);

    Livewire::withQueryParams(['history' => 'vod'])
        ->test(WatchProgressRelationManager::class, [
            'ownerRecord' => $this->viewer,
            'pageClass' => ViewPlaylistViewer::class,
        ])
        ->assertSet('activeTab', 'vod')
        ->call('loadTable')
        ->assertCanSeeTableRecords([$vod]);
});

it('searches TMDB and adds the clicked poster result from the Add Progress slide-over', function () {
    $this->actingAs($this->user);

    $component = Livewire::test(WatchProgressRelationManager::class, [
        'ownerRecord' => $this->viewer,
        'pageClass' => ViewPlaylistViewer::class,
    ])
        ->set('activeTab', 'vod')
        ->mountAction(TestAction::make('addProgress')->table())
        ->fillForm(['media_type' => 'movie', 'search_query' => 'Fight Club'])
        ->callAction(TestAction::make('searchTmdb')->schemaComponent('tmdbSearch'))
        ->assertMountedActionModalSee('Fight Club 2')
        ->assertMountedActionModalSeeHtml('https://image.tmdb.org/t/p/w92/fight-club.jpg')
        // Cards write into the sibling hidden field rather than calling applyTmdbSelection().
        ->assertMountedActionModalSeeHtml('mountedActions.0.data.tmdb_id')
        ->assertMountedActionModalDontSeeHtml('applyTmdbSelection');

    // Submitting before picking a result is blocked.
    $component->callMountedAction()->assertNotified('Select a title from the search results');
    expect(ViewerWatchProgress::count())->toBe(0);

    // Clicking a card $set()s the hidden tmdb_id, which pre-fills the duration.
    $component->set('mountedActions.0.data.tmdb_id', 550)
        ->assertSchemaStateSet(['duration' => '2:19:00'])
        ->assertMountedActionModalSeeHtml('border-primary-500')
        ->fillForm(['position' => '10:00'])
        ->callMountedAction()
        ->assertHasNoFormErrors()
        ->assertNotified('Watch progress added');

    expect(ViewerWatchProgress::sole())
        ->tmdb_id->toBe(550)
        ->position_seconds->toBe(600)
        ->duration_seconds->toBe(8340);
});

it('leaves the VOD manual TMDB search results applying the selection directly', function () {
    $this->actingAs($this->user);

    $vod = Channel::factory()->create(['user_id' => $this->user->id, 'playlist_id' => $this->playlist->id, 'is_vod' => true]);

    Livewire::test(ListVod::class)
        ->loadTable()
        ->mountAction(TestAction::make('manual_tmdb_search')->table($vod))
        ->set('mountedActions.0.data.search_results', [
            ['id' => 550, 'title' => 'Fight Club', 'name' => 'Fight Club', 'year' => '1999', 'poster_path' => '/fight-club.jpg', 'vote_average' => 8.4],
        ])
        ->assertMountedActionModalSeeHtml("applyTmdbSelection(550, 'movie', {$vod->id}, 'vod')")
        ->assertMountedActionModalDontSeeHtml('border-primary-500');
});
