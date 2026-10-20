<?php

/**
 * Issue #1636: provider category reorganisations on Xtream playlists.
 *
 * Xtream channels are keyed by stream_id and `group_id` is left out of the chunk
 * upsert (so manual moves survive a sync), which meant a channel the provider moved
 * to another category stayed in its old group forever, leaving the new group empty.
 * Separately, the "clear previous new flags" step ran after this run's groups were
 * created, so added groups were never logged and "New Groups Only" auto-sync rules
 * never matched anything.
 */

use App\Enums\Status;
use App\Enums\SyncRunPhase;
use App\Jobs\AutoSyncGroupsToCustomPlaylist;
use App\Jobs\ProcessM3uImport;
use App\Jobs\ProcessM3uImportChunk;
use App\Jobs\ProcessM3uImportComplete;
use App\Jobs\ProcessM3uVodImportChunk;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Group;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\PlaylistSyncStatus;
use App\Models\PlaylistSyncStatusLog;
use App\Models\User;
use App\Services\SyncPipelineService;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->tempJobsDb = sys_get_temp_dir().'/jobs_test_'.uniqid().'.sqlite';
    touch($this->tempJobsDb);
    config(['database.connections.jobs.database' => $this->tempJobsDb]);
    DB::purge('jobs');

    $migration = require database_path('migrations/2025_02_13_215803_create_jobs_table.php');
    $migration->up();

    $this->user = User::factory()->create();

    // Mutable provider feed, so each sync in a test can serve a different catalog
    $this->feed = new ArrayObject([
        'live_categories' => [],
        'live_streams' => [],
        'vod_categories' => [],
        'vod_streams' => [],
    ]);
    $feed = $this->feed;
    Http::fake([
        'http://xtream.example.test:8080/player_api.php*' => function ($request) use ($feed) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return match ($query['action'] ?? null) {
                'get_live_categories' => Http::response(json_encode($feed['live_categories']), 200),
                'get_live_streams' => Http::response(json_encode($feed['live_streams']), 200),
                'get_vod_categories' => Http::response(json_encode($feed['vod_categories']), 200),
                'get_vod_streams' => Http::response(json_encode($feed['vod_streams']), 200),
                default => Http::response(json_encode(['user_info' => ['status' => 'Active']]), 200),
            };
        },
    ]);
});

afterEach(function () {
    DB::purge('jobs');
    config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);

    if (isset($this->tempJobsDb) && file_exists($this->tempJobsDb)) {
        @unlink($this->tempJobsDb);
    }
    if (isset($this->tempM3uPath) && file_exists($this->tempM3uPath)) {
        @unlink($this->tempM3uPath);
    }
});

function providerGroupMovePlaylist(User $user, array $importOptions = ['live']): Playlist
{
    return Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://xtream.example.test:8080',
            'username' => 'user',
            'password' => 'pass',
            'output' => 'ts',
            'import_options' => $importOptions,
        ],
        'import_prefs' => [],
        'auto_sort' => false,
    ]));
}

/**
 * Run a full Xtream sync (import, chunk upserts, completion) against the given catalog.
 *
 * @param  array<string, list<array<string, mixed>>>  $catalog  Keys of the provider feed to replace.
 */
function providerGroupMoveSync(Playlist $playlist, ArrayObject $feed, array $catalog, bool $isNew = false): void
{
    foreach ($catalog as $key => $value) {
        $feed[$key] = $value;
    }

    Bus::fake();
    $start = now();
    (new ProcessM3uImport($playlist->fresh(), force: true, isNew: $isNew))->handle();

    $jobs = Job::all();
    [$vodJobs, $liveJobs] = $jobs->partition(fn (Job $job) => ($job->variables['type'] ?? 'live') === 'vod');
    if ($liveJobs->isNotEmpty()) {
        (new ProcessM3uImportChunk($liveJobs->pluck('id')->all(), $liveJobs->count()))->handle();
    }
    if ($vodJobs->isNotEmpty()) {
        (new ProcessM3uVodImportChunk($vodJobs->pluck('id')->all(), $vodJobs->count()))->handle();
    }

    $importOptions = $playlist->xtream_config['import_options'];
    (new ProcessM3uImportComplete(
        userId: $playlist->user_id,
        playlistId: $playlist->id,
        batchNo: $jobs->first()->batch_no,
        start: $start,
        isNew: $isNew,
        runningLiveImport: in_array('live', $importOptions),
        runningVodImport: in_array('vod', $importOptions),
    ))->handle(app(GeneralSettings::class));
}

function providerGroupMoveGroup(Playlist $playlist, string $name, string $type = 'live'): ?Group
{
    return Group::withTrashed()
        ->where('playlist_id', $playlist->id)
        ->where('name_internal', $name)
        ->where('type', $type)
        ->first();
}

function providerGroupMoveChannel(Playlist $playlist, int $streamId): ?Channel
{
    return Channel::where('playlist_id', $playlist->id)->where('source_id', (string) $streamId)->first();
}

describe('channels moved by the provider', function () {
    it('moves a channel into the category the provider moved it to', function () {
        $playlist = providerGroupMovePlaylist($this->user);
        providerGroupMoveSync($playlist, $this->feed, [
            'live_categories' => [
                ['category_id' => '1', 'category_name' => 'Sports'],
                ['category_id' => '2', 'category_name' => 'News'],
            ],
            'live_streams' => [
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '1'],
                ['stream_id' => 101, 'name' => 'BT Sport', 'category_id' => '1'],
                ['stream_id' => 200, 'name' => 'BBC News', 'category_id' => '2'],
            ],
        ], isNew: true);

        providerGroupMoveSync($playlist, $this->feed, [
            'live_categories' => [
                ['category_id' => '1', 'category_name' => 'Sports'],
                ['category_id' => '2', 'category_name' => 'News'],
                ['category_id' => '3', 'category_name' => 'Sports UK'],
            ],
            'live_streams' => [
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '3'],
                ['stream_id' => 101, 'name' => 'BT Sport', 'category_id' => '1'],
                ['stream_id' => 200, 'name' => 'BBC News', 'category_id' => '2'],
            ],
        ]);

        $sportsUk = providerGroupMoveGroup($playlist, 'Sports UK');
        $moved = providerGroupMoveChannel($playlist, 100);

        expect($moved->group_id)->toBe($sportsUk->id)
            ->and($moved->group)->toBe('Sports UK')
            ->and(providerGroupMoveChannel($playlist, 101)->group_id)->toBe(providerGroupMoveGroup($playlist, 'Sports')->id)
            ->and($sportsUk->channels()->count())->toBe(1);
    });

    it('moves a channel out of a category the provider removed', function () {
        $playlist = providerGroupMovePlaylist($this->user);
        providerGroupMoveSync($playlist, $this->feed, [
            'live_categories' => [
                ['category_id' => '1', 'category_name' => 'Sports'],
                ['category_id' => '2', 'category_name' => 'News'],
            ],
            'live_streams' => [
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '1'],
                ['stream_id' => 200, 'name' => 'BBC News', 'category_id' => '2'],
            ],
        ], isNew: true);

        providerGroupMoveSync($playlist, $this->feed, [
            'live_categories' => [
                ['category_id' => '2', 'category_name' => 'News'],
                ['category_id' => '3', 'category_name' => 'Sports UK'],
            ],
            'live_streams' => [
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '3'],
                ['stream_id' => 200, 'name' => 'BBC News', 'category_id' => '2'],
            ],
        ]);

        expect(providerGroupMoveChannel($playlist, 100)->group_id)->toBe(providerGroupMoveGroup($playlist, 'Sports UK')->id)
            ->and(providerGroupMoveGroup($playlist, 'Sports')->trashed())->toBeTrue();
    });

    it('re-homes a channel an earlier sync left in a soft-deleted group', function () {
        $playlist = providerGroupMovePlaylist($this->user);
        providerGroupMoveSync($playlist, $this->feed, [
            'live_categories' => [
                ['category_id' => '1', 'category_name' => 'Sports'],
                ['category_id' => '2', 'category_name' => 'News'],
            ],
            'live_streams' => [
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '1'],
                ['stream_id' => 200, 'name' => 'BBC News', 'category_id' => '2'],
            ],
        ], isNew: true);

        // State left behind before the fix: the old group was removed, the channel's
        // provider group was updated, but the channel still points at the trashed group.
        providerGroupMoveGroup($playlist, 'Sports')->delete();
        providerGroupMoveChannel($playlist, 100)->update(['group_internal' => 'Sports UK']);

        providerGroupMoveSync($playlist, $this->feed, [
            'live_categories' => [
                ['category_id' => '2', 'category_name' => 'News'],
                ['category_id' => '3', 'category_name' => 'Sports UK'],
            ],
            'live_streams' => [
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '3'],
                ['stream_id' => 200, 'name' => 'BBC News', 'category_id' => '2'],
            ],
        ]);

        expect(providerGroupMoveChannel($playlist, 100)->group_id)->toBe(providerGroupMoveGroup($playlist, 'Sports UK')->id);
    });

    it('keeps channels the user moved to another group', function () {
        $playlist = providerGroupMovePlaylist($this->user);
        $catalog = [
            'live_categories' => [
                ['category_id' => '1', 'category_name' => 'Sports'],
                ['category_id' => '2', 'category_name' => 'News'],
            ],
            'live_streams' => [
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '1'],
                ['stream_id' => 101, 'name' => 'BT Sport', 'category_id' => '1'],
                ['stream_id' => 200, 'name' => 'BBC News', 'category_id' => '2'],
            ],
        ];
        providerGroupMoveSync($playlist, $this->feed, $catalog, isNew: true);

        $news = providerGroupMoveGroup($playlist, 'News');
        $favourites = Group::factory()->for($playlist)->for($this->user)->create([
            'name' => 'Favourites',
            'name_internal' => 'Favourites',
            'custom' => true,
            'type' => 'live',
        ]);
        providerGroupMoveChannel($playlist, 100)->update(['group' => $news->name, 'group_id' => $news->id]);
        providerGroupMoveChannel($playlist, 101)->update(['group' => $favourites->name, 'group_id' => $favourites->id]);

        // Provider listing unchanged
        providerGroupMoveSync($playlist, $this->feed, $catalog);

        expect(providerGroupMoveChannel($playlist, 100)->group_id)->toBe($news->id)
            ->and(providerGroupMoveChannel($playlist, 101)->group_id)->toBe($favourites->id);

        // Provider moves both: the user's placement still wins
        providerGroupMoveSync($playlist, $this->feed, [
            'live_categories' => [
                ['category_id' => '1', 'category_name' => 'Sports'],
                ['category_id' => '2', 'category_name' => 'News'],
                ['category_id' => '3', 'category_name' => 'Sports UK'],
            ],
            'live_streams' => [
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '3'],
                ['stream_id' => 101, 'name' => 'BT Sport', 'category_id' => '3'],
                ['stream_id' => 200, 'name' => 'BBC News', 'category_id' => '2'],
            ],
        ]);

        expect(providerGroupMoveChannel($playlist, 100)->group_id)->toBe($news->id)
            ->and(providerGroupMoveChannel($playlist, 101)->group_id)->toBe($favourites->id);
    });

    it('keeps a stream listed in several categories in the first one', function () {
        $playlist = providerGroupMovePlaylist($this->user);
        $catalog = [
            'live_categories' => [
                ['category_id' => '1', 'category_name' => 'Sports'],
                ['category_id' => '2', 'category_name' => 'Football'],
            ],
            'live_streams' => [
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '1'],
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '2'],
                ['stream_id' => 101, 'name' => 'Sky Football', 'category_id' => '2'],
            ],
        ];
        providerGroupMoveSync($playlist, $this->feed, $catalog, isNew: true);
        providerGroupMoveSync($playlist, $this->feed, $catalog);
        providerGroupMoveSync($playlist, $this->feed, $catalog);

        expect(providerGroupMoveChannel($playlist, 100)->group_id)->toBe(providerGroupMoveGroup($playlist, 'Sports')->id);
    });

    it('moves a stream listed in several categories once when another category lists it first', function () {
        $playlist = providerGroupMovePlaylist($this->user);
        $categories = [
            ['category_id' => '1', 'category_name' => 'Sports'],
            ['category_id' => '2', 'category_name' => 'Football'],
        ];
        providerGroupMoveSync($playlist, $this->feed, [
            'live_categories' => $categories,
            'live_streams' => [
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '1'],
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '2'],
            ],
        ], isNew: true);

        $flipped = [
            'live_categories' => $categories,
            'live_streams' => [
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '2'],
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '1'],
            ],
        ];
        $football = providerGroupMoveGroup($playlist, 'Football');
        foreach (range(1, 3) as $sync) {
            providerGroupMoveSync($playlist, $this->feed, $flipped);

            expect(providerGroupMoveChannel($playlist, 100))
                ->group_id->toBe($football->id)
                ->group_internal->toBe('Football');
        }
    });

    it('follows a stream listed in several categories when the provider drops it from the first one', function () {
        $playlist = providerGroupMovePlaylist($this->user);
        $categories = [
            ['category_id' => '1', 'category_name' => 'Sports'],
            ['category_id' => '2', 'category_name' => 'Football'],
        ];
        providerGroupMoveSync($playlist, $this->feed, [
            'live_categories' => $categories,
            'live_streams' => [
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '1'],
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '2'],
                ['stream_id' => 101, 'name' => 'BT Sport', 'category_id' => '1'],
            ],
        ], isNew: true);

        providerGroupMoveSync($playlist, $this->feed, [
            'live_categories' => $categories,
            'live_streams' => [
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '2'],
                ['stream_id' => 101, 'name' => 'BT Sport', 'category_id' => '1'],
            ],
        ]);

        expect(providerGroupMoveChannel($playlist, 100)->group_id)->toBe(providerGroupMoveGroup($playlist, 'Football')->id);
    });

    it('does not move channels into a blank category when the category list comes back empty', function () {
        $playlist = providerGroupMovePlaylist($this->user);
        $streams = [
            ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '1'],
            ['stream_id' => 200, 'name' => 'BBC News', 'category_id' => '2'],
        ];
        $categories = [
            ['category_id' => '1', 'category_name' => 'Sports'],
            ['category_id' => '2', 'category_name' => 'News'],
        ];
        providerGroupMoveSync($playlist, $this->feed, ['live_categories' => $categories, 'live_streams' => $streams], isNew: true);
        $sports = providerGroupMoveGroup($playlist, 'Sports');
        $news = providerGroupMoveGroup($playlist, 'News');

        providerGroupMoveSync($playlist, $this->feed, ['live_categories' => [], 'live_streams' => $streams]);

        expect(providerGroupMoveChannel($playlist, 100)->group_id)->toBe($sports->id)
            ->and(providerGroupMoveChannel($playlist, 200)->group_id)->toBe($news->id)
            ->and(providerGroupMoveGroup($playlist, '')->channels()->count())->toBe(0);

        providerGroupMoveSync($playlist, $this->feed, ['live_categories' => $categories, 'live_streams' => $streams]);

        expect(providerGroupMoveChannel($playlist, 100)->group_id)->toBe($sports->id)
            ->and($sports->fresh()->trashed())->toBeFalse();
    });

    it('moves a VOD channel into the category the provider moved it to', function () {
        $playlist = providerGroupMovePlaylist($this->user, ['vod']);
        providerGroupMoveSync($playlist, $this->feed, [
            'vod_categories' => [
                ['category_id' => '10', 'category_name' => 'Action'],
                ['category_id' => '11', 'category_name' => 'Comedy'],
            ],
            'vod_streams' => [
                ['stream_id' => 500, 'name' => 'Movie A', 'category_id' => '10', 'container_extension' => 'mkv'],
                ['stream_id' => 501, 'name' => 'Movie B', 'category_id' => '11', 'container_extension' => 'mkv'],
                ['stream_id' => 502, 'name' => 'Movie C', 'category_id' => '10', 'container_extension' => 'mkv'],
            ],
        ], isNew: true);

        providerGroupMoveSync($playlist, $this->feed, [
            'vod_streams' => [
                ['stream_id' => 500, 'name' => 'Movie A', 'category_id' => '11', 'container_extension' => 'mkv'],
                ['stream_id' => 501, 'name' => 'Movie B', 'category_id' => '11', 'container_extension' => 'mkv'],
                ['stream_id' => 502, 'name' => 'Movie C', 'category_id' => '10', 'container_extension' => 'mkv'],
            ],
        ]);

        expect(providerGroupMoveChannel($playlist, 500)->group_id)->toBe(providerGroupMoveGroup($playlist, 'Comedy', 'vod')->id)
            ->and(providerGroupMoveChannel($playlist, 502)->group_id)->toBe(providerGroupMoveGroup($playlist, 'Action', 'vod')->id);
    });
});

describe('groups added by a sync', function () {
    it('flags and logs the groups an Xtream sync added', function () {
        $playlist = providerGroupMovePlaylist($this->user);
        providerGroupMoveSync($playlist, $this->feed, [
            'live_categories' => [['category_id' => '1', 'category_name' => 'Sports']],
            'live_streams' => [['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '1']],
        ], isNew: true);

        providerGroupMoveSync($playlist, $this->feed, [
            'live_categories' => [
                ['category_id' => '1', 'category_name' => 'Sports'],
                ['category_id' => '2', 'category_name' => 'News'],
            ],
            'live_streams' => [
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '1'],
                ['stream_id' => 200, 'name' => 'BBC News', 'category_id' => '2'],
            ],
        ]);

        $sync = PlaylistSyncStatus::where('playlist_id', $playlist->id)->latest('id')->first();

        expect(providerGroupMoveGroup($playlist, 'News')->new)->toBeTruthy()
            ->and(providerGroupMoveGroup($playlist, 'Sports')->new)->toBeFalsy()
            ->and($sync->sync_stats['added_groups'])->toBe(1)
            ->and(PlaylistSyncStatusLog::where([
                'playlist_sync_status_id' => $sync->id,
                'type' => 'group',
                'status' => 'added',
            ])->pluck('name')->all())->toBe(['News']);
    });

    it('flags the groups an M3U sync created and clears last sync\'s flags', function () {
        $this->tempM3uPath = sys_get_temp_dir().'/playlist_group_move_'.uniqid().'.m3u';
        file_put_contents($this->tempM3uPath, implode("\n", [
            '#EXTM3U',
            '#EXTINF:-1 tvg-name="BBC News" group-title="News",BBC News',
            'http://example.test/stream/1',
            '#EXTINF:-1 tvg-name="Sky Sports" group-title="Sports",Sky Sports',
            'http://example.test/stream/2',
        ]));

        $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($this->user)->create([
            'url' => $this->tempM3uPath,
            'xtream' => false,
            'import_prefs' => [],
            'auto_sort' => false,
        ]));
        $news = Group::factory()->for($playlist)->for($this->user)->create([
            'name' => 'News',
            'name_internal' => 'News',
            'custom' => false,
            'type' => 'live',
            'new' => true,
            'import_batch_no' => 'previous-batch',
        ]);

        Bus::fake();
        (new ProcessM3uImport($playlist, force: true, isNew: false))->handle();

        expect($news->fresh()->new)->toBeFalsy()
            ->and(providerGroupMoveGroup($playlist, 'Sports')->new)->toBeTruthy();
    });

    it('only hands groups created this run to New Groups Only auto-sync rules', function () {
        $playlist = providerGroupMovePlaylist($this->user);
        $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
        $playlist->updateQuietly(['auto_sync_to_custom_config' => [[
            'enabled' => true,
            'custom_playlist_id' => $customPlaylist->id,
            'type' => 'live_groups',
            'group_filter' => 'new_only',
        ]]]);

        // Restored from the trash this run: flagged new for the sync log, but not a new group
        $this->travelTo(now()->subDay());
        Group::factory()->for($playlist)->for($this->user)->create(['type' => 'live', 'new' => true]);
        $this->travelBack();

        $pipeline = app(SyncPipelineService::class);
        $run = $pipeline->startImport($playlist);
        $created = Group::factory()->for($playlist)->for($this->user)->create(['type' => 'live', 'new' => true]);

        Bus::fake();
        $pipeline->dispatchPhase($run, SyncRunPhase::CustomPlaylistSync);

        Bus::assertDispatched(
            AutoSyncGroupsToCustomPlaylist::class,
            fn (AutoSyncGroupsToCustomPlaylist $job): bool => $job->groupIds === [$created->id],
        );
    });
});

describe('invalidated syncs', function () {
    beforeEach(function () {
        config([
            'dev.invalidate_import' => true,
            'dev.invalidate_import_threshold' => 0,
        ]);
    });

    it('removes the groups an invalidated sync created', function () {
        $playlist = providerGroupMovePlaylist($this->user);
        providerGroupMoveSync($playlist, $this->feed, [
            'live_categories' => [['category_id' => '1', 'category_name' => 'Sports']],
            'live_streams' => [
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '1'],
                ['stream_id' => 101, 'name' => 'BT Sport', 'category_id' => '1'],
                ['stream_id' => 102, 'name' => 'Eurosport', 'category_id' => '1'],
            ],
        ], isNew: true);

        providerGroupMoveSync($playlist, $this->feed, [
            'live_categories' => [
                ['category_id' => '1', 'category_name' => 'Sports'],
                ['category_id' => '5', 'category_name' => 'Extra'],
            ],
            'live_streams' => [
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '1'],
                ['stream_id' => 300, 'name' => 'Extra One', 'category_id' => '5'],
            ],
        ]);

        expect($playlist->fresh()->status)->toBe(Status::Failed)
            ->and(providerGroupMoveGroup($playlist, 'Extra')->trashed())->toBeTrue()
            ->and(providerGroupMoveChannel($playlist, 300))->toBeNull()
            ->and(providerGroupMoveChannel($playlist, 101))->not->toBeNull();
    });

    it('keeps a group an invalidated sync moved existing channels into', function () {
        $playlist = providerGroupMovePlaylist($this->user);
        providerGroupMoveSync($playlist, $this->feed, [
            'live_categories' => [['category_id' => '1', 'category_name' => 'Sports']],
            'live_streams' => [
                ['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '1'],
                ['stream_id' => 101, 'name' => 'BT Sport', 'category_id' => '1'],
                ['stream_id' => 102, 'name' => 'Eurosport', 'category_id' => '1'],
            ],
        ], isNew: true);

        providerGroupMoveSync($playlist, $this->feed, [
            'live_categories' => [['category_id' => '3', 'category_name' => 'Sports UK']],
            'live_streams' => [['stream_id' => 100, 'name' => 'Sky Sports', 'category_id' => '3']],
        ]);

        $sportsUk = providerGroupMoveGroup($playlist, 'Sports UK');

        expect($playlist->fresh()->status)->toBe(Status::Failed)
            ->and($sportsUk->trashed())->toBeFalse()
            ->and(providerGroupMoveChannel($playlist, 100)->group_id)->toBe($sportsUk->id);
    });
});
