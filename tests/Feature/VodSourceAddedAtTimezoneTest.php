<?php

/**
 * The provider's VOD `added` timestamp was saved as a UTC wall clock but read back in the
 * app timezone, so the Xtream API's `added` was off by the UTC offset on non-UTC installs.
 */

use App\Jobs\ProcessM3uImport;
use App\Jobs\ProcessM3uVodImportChunk;
use App\Models\Channel;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const VOD_ADDED_TIMESTAMP = 1614834367;

beforeEach(function () {
    $this->originalTimezone = date_default_timezone_get();

    $this->tempJobsDb = sys_get_temp_dir().'/jobs_test_'.uniqid().'.sqlite';
    touch($this->tempJobsDb);
    config(['database.connections.jobs.database' => $this->tempJobsDb]);
    DB::purge('jobs');
    (require database_path('migrations/2025_02_13_215803_create_jobs_table.php'))->up();

    Http::fake([
        'http://xtream.example.test:8080/player_api.php*' => function ($request) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);

            return match ($query['action'] ?? null) {
                'get_vod_categories' => Http::response([['category_id' => '1', 'category_name' => 'Movies']]),
                'get_vod_streams' => Http::response([[
                    'stream_id' => 100,
                    'name' => 'The Matrix',
                    'category_id' => '1',
                    'container_extension' => 'mkv',
                    'added' => (string) VOD_ADDED_TIMESTAMP,
                ]]),
                'get_live_categories', 'get_live_streams', 'get_series_categories', 'get_series' => Http::response([]),
                default => Http::response(['user_info' => ['status' => 'Active']]),
            };
        },
    ]);

    $this->playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for(User::factory()->create())->create([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://xtream.example.test:8080',
            'username' => 'user',
            'password' => 'pass',
            'output' => 'ts',
            'import_options' => ['vod'],
        ],
        'import_prefs' => [],
        'auto_sort' => false,
    ]));
});

afterEach(function () {
    date_default_timezone_set($this->originalTimezone);

    DB::purge('jobs');
    config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);
    @unlink($this->tempJobsDb);
});

function vodAddedRunImport(Playlist $playlist): void
{
    Bus::fake();
    Job::query()->delete();

    (new ProcessM3uImport($playlist->fresh(), force: true))->handle();

    $jobs = Job::all();
    (new ProcessM3uVodImportChunk($jobs->pluck('id')->all(), $jobs->count()))->handle();
}

it('stores the provider added date as the same instant', function (string $timezone) {
    date_default_timezone_set($timezone);
    config(['app.timezone' => $timezone]);

    vodAddedRunImport($this->playlist);

    expect(Channel::where('source_id', '100')->sole()->source_added_at->timestamp)->toBe(VOD_ADDED_TIMESTAMP);
})->with(['America/Denver', 'Asia/Tokyo']);

it('corrects a date saved in UTC on the next sync', function () {
    date_default_timezone_set('America/Denver');
    config(['app.timezone' => 'America/Denver']);

    vodAddedRunImport($this->playlist);
    Channel::where('source_id', '100')->update(['source_added_at' => '2021-03-04 05:06:07']);

    vodAddedRunImport($this->playlist);

    expect(Channel::where('source_id', '100')->sole()->source_added_at->timestamp)->toBe(VOD_ADDED_TIMESTAMP);
});
