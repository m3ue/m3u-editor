<?php

use App\Enums\EpgSourceType;
use App\Models\Epg;
use App\Models\User;
use App\Services\SchedulesDirectCacheStore;
use App\Services\SchedulesDirectService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

/**
 * A fake SchedulesDirect account whose data the tests change between syncs.
 * Station-day and program MD5s are derived from their content, as SD does.
 */
beforeEach(function () {
    Sleep::fake();
    Storage::fake('local');
    Http::preventStrayRequests();

    $this->today = now()->format('Y-m-d');
    $this->lineupModified = '2026-10-01T00:00:00Z';
    // stationID => programIDs airing on it today
    $this->stations = ['1' => ['EP0000000001'], '2' => ['EP0000000002']];
    // programID => title
    $this->programs = ['EP0000000001' => 'Show One', 'EP0000000002' => 'Show Two'];
    $this->md5Rejected = false;
    // Programs updated at SD: /programs answers with the new MD5, and the
    // schedules list it only once SD has rebuilt them
    $this->programsUpdated = false;
    $this->schedulesRebuilt = false;

    $this->epg = Epg::factory()->create([
        'user_id' => User::factory()->create()->id,
        'source_type' => EpgSourceType::SCHEDULES_DIRECT,
        'sd_username' => 'test@example.com',
        'sd_password' => 'password',
        'sd_token' => 'valid-token',
        'sd_token_expires_at' => now()->addHour(),
        'sd_lineup_ids' => ['USA-A'],
        'sd_days_to_import' => 1,
    ]);

    $programMd5 = fn (string $programId) => md5($this->programs[$programId]);
    $updatedMd5 = fn (string $programId) => md5("updated {$programId}");
    $schedule = fn (string $stationId) => [
        'stationID' => $stationId,
        'programs' => array_map(fn (string $programId) => [
            'programID' => $programId,
            'airDateTime' => now()->startOfDay()->toIso8601ZuluString(),
            'duration' => 1800,
            'md5' => $this->programsUpdated && $this->schedulesRebuilt ? $updatedMd5($programId) : $programMd5($programId),
        ], $this->stations[$stationId]),
    ];
    $scheduleMd5 = fn (string $stationId) => md5(json_encode($schedule($stationId)));

    Http::fake([
        'json.schedulesdirect.org/20141201/status' => fn () => Http::response([
            'code' => 0,
            'lineups' => [['lineup' => 'USA-A', 'modified' => $this->lineupModified]],
        ]),
        'json.schedulesdirect.org/20141201/lineups/USA-A' => fn () => Http::response([
            'map' => array_map(fn ($id) => ['stationID' => (string) $id, 'channel' => (string) $id], array_keys($this->stations)),
            'stations' => array_map(fn ($id) => ['stationID' => (string) $id, 'name' => "Station {$id}"], array_keys($this->stations)),
        ]),
        'json.schedulesdirect.org/20141201/schedules/md5' => fn (Request $request) => $this->md5Rejected
            ? Http::response(['code' => 1004, 'message' => 'Bad request'], 400)
            : Http::response(collect($request->data())->mapWithKeys(fn (array $station) => [
                $station['stationID'] => [$this->today => ['code' => 0, 'md5' => $scheduleMd5($station['stationID'])]],
            ])->all()),
        'json.schedulesdirect.org/20141201/schedules' => fn (Request $request) => Http::response(array_map(
            fn (array $station) => [
                ...$schedule($station['stationID']),
                'metadata' => ['startDate' => $this->today, 'md5' => $scheduleMd5($station['stationID'])],
            ],
            $request->data(),
        )),
        'json.schedulesdirect.org/20141201/programs' => fn (Request $request) => Http::response(array_map(
            fn (string $programId) => [
                'programID' => $programId,
                'md5' => $this->programsUpdated ? $updatedMd5($programId) : $programMd5($programId),
                'titles' => [['title120' => $this->programs[$programId]]],
                'cast' => [['name' => 'Not cached']],
            ],
            $request->data(),
        )),
    ]);

    $this->sync = function (): void {
        app(SchedulesDirectService::class)->syncEpgData($this->epg->fresh());
    };
    $this->cachePath = fn () => Storage::disk('local')->path("{$this->epg->folder_path}/".SchedulesDirectService::CACHE_FILE);
    $this->cacheColumn = fn (string $sql) => (new PDO('sqlite:'.($this->cachePath)()))->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $this->requestsTo = fn (string $method, string $path) => Http::recorded(
        fn (Request $request) => $request->method() === $method
            && $request->url() === "https://json.schedulesdirect.org/20141201{$path}"
    )->map(fn (array $pair) => $pair[0]);
});

it('downloads nothing but MD5s and status when nothing changed since the last sync', function () {
    ($this->sync)();
    $firstGuide = Storage::disk('local')->get($this->epg->file_path);
    $before = Http::recorded()->count();

    ($this->sync)();

    $secondSync = Http::recorded()->slice($before)->map(fn (array $pair) => $pair[0]->url())->values()->all();
    expect($secondSync)->toBe([
        'https://json.schedulesdirect.org/20141201/status',
        'https://json.schedulesdirect.org/20141201/schedules/md5',
    ])
        ->and(Storage::disk('local')->get($this->epg->file_path))->toBe($firstGuide)
        ->and($firstGuide)->toContain('Show One')->toContain('Show Two');
});

it('downloads only the changed station-days and the new or changed programs', function () {
    ($this->sync)();
    $before = Http::recorded()->count();

    // Station 2 now also airs a new program, and program 2 was edited
    $this->stations['2'][] = 'EP0000000003';
    $this->programs['EP0000000003'] = 'Show Three';
    $this->programs['EP0000000002'] = 'Show Two (Edited)';

    ($this->sync)();

    $second = Http::recorded()->slice($before)->map(fn (array $pair) => $pair[0]);
    $schedules = $second->filter(fn (Request $request) => str_ends_with($request->url(), '/schedules'));
    $programs = $second->filter(fn (Request $request) => str_ends_with($request->url(), '/programs'));

    expect($schedules->map->data()->values()->all())->toBe([[['stationID' => '2', 'date' => [$this->today]]]])
        ->and($programs->flatMap->data()->sort()->values()->all())->toBe(['EP0000000002', 'EP0000000003'])
        ->and(Storage::disk('local')->get($this->epg->file_path))
        ->toContain('Show One')
        ->toContain('Show Two (Edited)')
        ->toContain('Show Three');
});

it('requests a program carried on stations in different sub-chunks only once per sync', function () {
    // 20 stations split into 10 sub-chunks, every one airing the same network show
    $this->stations = collect(range(1, 20))->mapWithKeys(fn (int $id) => [(string) $id => ['EP0000000001']])->all();

    ($this->sync)();

    $requested = ($this->requestsTo)('POST', '/programs')->flatMap->data()->all();
    expect($requested)->toBe(['EP0000000001'])
        ->and(substr_count(Storage::disk('local')->get($this->epg->file_path), 'Show One'))->toBe(20);
});

it('reuses the cached lineup until its modified stamp changes', function () {
    ($this->sync)();
    ($this->sync)();
    expect(($this->requestsTo)('GET', '/lineups/USA-A'))->toHaveCount(1);

    $this->lineupModified = '2026-10-29T00:00:00Z';
    ($this->sync)();
    expect(($this->requestsTo)('GET', '/lineups/USA-A'))->toHaveCount(2);
});

it('keeps only the program fields the guide uses in the cache', function () {
    ($this->sync)();

    $data = json_decode(gzinflate(($this->cacheColumn)("SELECT data FROM programs WHERE program_id = 'EP0000000001'")[0]), true);

    expect($data)->toHaveKeys(['programID', 'md5', 'titles'])
        ->not->toHaveKey('cast');
});

it('drops cached programs and stations a later sync no longer uses', function () {
    ($this->sync)();
    expect(($this->cacheColumn)('SELECT program_id FROM programs'))->toHaveCount(2);

    // Removing a station from the lineup changes its modified stamp
    unset($this->stations['2']);
    $this->lineupModified = '2026-10-29T00:00:00Z';
    ($this->sync)();

    expect(($this->cacheColumn)('SELECT program_id FROM programs'))->toBe(['EP0000000001'])
        ->and(($this->cacheColumn)('SELECT station_id FROM schedules'))->toBe(['1']);
});

it('drops cached station-days before today', function () {
    $cache = SchedulesDirectCacheStore::open(($this->cachePath)());
    $cache->putSchedules([['station_id' => '1', 'date' => now()->subDay()->format('Y-m-d'), 'md5' => 'old', 'schedule' => []]]);
    $cache->close();

    ($this->sync)();

    expect(($this->cacheColumn)('SELECT date FROM schedules'))->toBe([$this->today, $this->today]);
});

it('downloads every station-day when the MD5 request is rejected', function () {
    $this->md5Rejected = true;

    ($this->sync)();

    expect(($this->requestsTo)('POST', '/schedules')->map->data()->first())->toBe([
        ['stationID' => '1', 'date' => [$this->today]],
        ['stationID' => '2', 'date' => [$this->today]],
    ])->and(Storage::disk('local')->get($this->epg->file_path))->toContain('Show One');
});

it('keeps program artwork with the cached program instead of fetching it every sync', function () {
    $this->epg->update(['sd_metadata' => ['enabled' => true]]);
    Http::fake([
        'json.schedulesdirect.org/20141201/metadata/programs/' => fn (Request $request) => Http::response(array_map(fn (string $programId) => [
            'programID' => $programId,
            'data' => [['uri' => "{$programId}.jpg", 'category' => 'Iconic', 'aspect' => '16x9', 'width' => 1920, 'height' => 1080, 'tier' => 'Series']],
        ], $request->data())),
    ]);

    ($this->sync)();
    $firstSyncArtworkRequests = ($this->requestsTo)('POST', '/metadata/programs/')->count();
    ($this->sync)();

    expect($firstSyncArtworkRequests)->toBeGreaterThan(0)
        ->and(($this->requestsTo)('POST', '/metadata/programs/'))->toHaveCount($firstSyncArtworkRequests)
        ->and(Storage::disk('local')->get($this->epg->file_path))->toContain('/image/EP0000000001.jpg');
});

it('stores the cache in its own SQLite file instead of the app database', function () {
    ($this->sync)();

    expect(($this->cachePath)())->toBeFile()
        ->and(($this->cacheColumn)('SELECT lineup_id FROM lineups'))->toBe(['USA-A']);
});

it('starts a new cache file when the existing one is unreadable or from another schema version', function (string $contents) {
    $path = ($this->cachePath)();
    mkdir(dirname($path), 0755, true);
    file_put_contents($path, $contents);

    ($this->sync)();

    expect(($this->cacheColumn)('PRAGMA user_version'))->toBe([SchedulesDirectCacheStore::SCHEMA_VERSION])
        ->and(Storage::disk('local')->get($this->epg->file_path))->toContain('Show One');
})->with([
    'corrupt file' => 'not a sqlite database',
    'empty file' => '',
]);

it('is deleted with its EPG', function () {
    ($this->sync)();
    $path = ($this->cachePath)();

    $this->epg->delete();

    expect($path)->not->toBeFile();
});

it('trusts the program MD5 and settles once SD rebuilds the schedules', function () {
    $this->programsUpdated = true;
    $programRequests = fn () => ($this->requestsTo)('POST', '/programs')->flatMap->data()->count();

    ($this->sync)();
    expect($programRequests())->toBe(2);

    // The schedules still list the old MD5, so the programs download again
    ($this->sync)();
    expect($programRequests())->toBe(4);

    // Rebuilt schedules list the MD5 already cached: nothing more to download
    $this->schedulesRebuilt = true;
    ($this->sync)();
    expect($programRequests())->toBe(4);

    expect(($this->cacheColumn)("SELECT md5 FROM programs WHERE program_id = 'EP0000000001'"))->toBe([md5('updated EP0000000001')]);
});

describe('program artwork', function () {
    beforeEach(function () {
        $this->epg->update(['sd_metadata' => ['enabled' => true]]);
        // 'none' (SD has no artwork), 'image' (one image per program) or 'error' (server error)
        $this->artworkResponse = 'none';
        Http::fake([
            'json.schedulesdirect.org/20141201/metadata/programs/' => fn (Request $request) => match ($this->artworkResponse) {
                'error' => Http::response('Bad Gateway', 502),
                'none' => Http::response(array_map(fn (string $programId) => ['programID' => $programId, 'data' => ['errorCode' => 6000]], $request->data())),
                'image' => Http::response(array_map(fn (string $programId) => [
                    'programID' => $programId,
                    'data' => [['uri' => "{$programId}.jpg", 'category' => 'Iconic', 'aspect' => '16x9', 'width' => 1920, 'height' => 1080, 'tier' => 'Series']],
                ], $request->data())),
            },
        ]);
        $this->artworkRequests = fn () => ($this->requestsTo)('POST', '/metadata/programs/')->count();
    });

    it('records programs with no artwork so the next sync does not ask again', function () {
        ($this->sync)();
        $firstSync = ($this->artworkRequests)();
        ($this->sync)();

        expect($firstSync)->toBeGreaterThan(0)
            ->and(($this->artworkRequests)())->toBe($firstSync);
    });

    it('checks programs with no artwork again after a day', function () {
        ($this->sync)();
        $firstSync = ($this->artworkRequests)();

        $this->artworkResponse = 'image';
        $this->travel(25)->hours();
        $this->today = now()->format('Y-m-d');
        $this->epg->update(['sd_token_expires_at' => now()->addHour()]);
        ($this->sync)();

        expect(($this->artworkRequests)())->toBeGreaterThan($firstSync)
            ->and(Storage::disk('local')->get($this->epg->file_path))->toContain('/image/EP0000000001.jpg');
    });

    it('stores artwork by image URI and builds the URL when writing the guide', function () {
        $this->artworkResponse = 'image';
        ($this->sync)();

        $artwork = json_decode(gzinflate(($this->cacheColumn)("SELECT artwork FROM programs WHERE program_id = 'EP0000000001'")[0]), true);

        expect($artwork[0])->toHaveKey('uri', 'EP0000000001.jpg')->not->toHaveKey('url')
            ->and(Storage::disk('local')->get($this->epg->file_path))
            ->toContain("/schedules-direct/{$this->epg->uuid}/image/EP0000000001.jpg");
    });

    it('keeps cached artwork when a program downloads again', function () {
        $this->artworkResponse = 'image';
        $this->programsUpdated = true;

        ($this->sync)();
        $firstSync = ($this->artworkRequests)();
        // The program downloads again while the schedules lag, but its artwork is kept
        ($this->sync)();

        expect(($this->artworkRequests)())->toBe($firstSync)
            ->and(Storage::disk('local')->get($this->epg->file_path))->toContain('/image/EP0000000001.jpg');
    });

    it('asks again on the next sync when the artwork request failed', function () {
        $this->artworkResponse = 'error';
        ($this->sync)();

        $this->artworkResponse = 'image';
        ($this->sync)();

        expect(Storage::disk('local')->get($this->epg->file_path))->toContain('/image/EP0000000001.jpg');
    });
});
