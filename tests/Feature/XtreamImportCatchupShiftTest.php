<?php

/**
 * Regression tests for the Xtream import side of the "only one day of catchup"
 * bug reported against m3u-tv.
 *
 * Root cause: Xtream's `tv_archive_duration` is days by ecosystem convention
 * (asserted by this repo's own API docs at XtreamApiController.php:125-127 and
 * since #1389 by resolveTvArchiveDuration at XtreamApiController.php:3474-3485,
 * which converts hours→days). The Xtream import path stored the value raw
 * into hours-denominated `shift`, so a provider advertising 7 (days) landed
 * as `shift = 7` (hours) and surfaced to clients as ceil(7/24) = 1 day after
 * #1389's correct export conversion. The fix multiplies by 24 at the ingest
 * site, mirroring the `catchup-days`/`tvg-rec` M3U path at
 * ProcessM3uImport.php:987-997 (covered separately by M3uImportCatchupShiftTest
 * / #1317).
 *
 * Pairs with m3u-tv's `feat/catchup-channel-list-titles` dialog work (the
 * one-day symptom was the client-side surface of this server-side bug).
 */

use App\Jobs\ProcessM3uImport;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\User;
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
});

afterEach(function () {
    DB::purge('jobs');
    config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);

    if (isset($this->tempJobsDb) && file_exists($this->tempJobsDb)) {
        @unlink($this->tempJobsDb);
    }
});

/**
 * Runs ProcessM3uImport against a faked Xtream playlist and returns the
 * queued channel payload row. Mirrors the helper in
 * M3uImportCatchupShiftTest.php:50-72.
 *
 * @param  int|string|null  $tvArchiveDuration  Wire-shape value to send
 *                                              (int and string both occur in the wild; the fix must handle both
 *                                              via the `(int)` cast).
 * @return array<string, mixed>
 */
function xtreamImportedChannelPayloadRow(User $user, int|string|null $tvArchiveDuration): array
{
    $stream = [
        'stream_id' => 1,
        'name' => 'Catchup One',
        'category_id' => '1',
        'tv_archive' => 1,
    ];
    if ($tvArchiveDuration !== null) {
        $stream['tv_archive_duration'] = $tvArchiveDuration;
    }

    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create([
        'xtream' => true,
        'xtream_config' => [
            'url' => 'http://xtream.example.test:8080',
            'username' => 'user',
            'password' => 'pass',
            'output' => 'ts',
            'import_options' => ['live'],
        ],
        'import_prefs' => [],
    ]));

    Http::fake([
        // Closure form is required because the import hits player_api.php
        // with multiple actions (auth probe, get_live_categories,
        // get_live_streams) — we need to switch on the action param.
        'http://xtream.example.test:8080/player_api.php*' => function ($request) use ($stream) {
            parse_str(parse_url($request->url(), PHP_URL_QUERY) ?? '', $query);
            $action = $query['action'] ?? null;

            return match ($action) {
                'get_live_streams' => Http::response(json_encode([$stream]), 200),
                'get_live_categories' => Http::response(json_encode([
                    ['category_id' => '1', 'category_name' => 'News'],
                ]), 200),
                default => Http::response(
                    json_encode(['user_info' => ['status' => 'Active']]),
                    200
                ),
            };
        },
    ]);

    Bus::fake();
    (new ProcessM3uImport($playlist, force: true, isNew: true))->handle();

    return Job::firstOrFail()->payload[0];
}

it('converts tv_archive_duration (int days) to hours when importing Xtream', function () {
    $user = User::factory()->create();

    $row = xtreamImportedChannelPayloadRow($user, 7);

    expect((int) $row['shift'])->toBe(7 * 24);
});

it('converts tv_archive_duration (string days) to hours when importing Xtream', function () {
    $user = User::factory()->create();

    $row = xtreamImportedChannelPayloadRow($user, '3');

    expect((int) $row['shift'])->toBe(3 * 24);
});

it('falls back to shift=0 when tv_archive_duration is absent', function () {
    $user = User::factory()->create();

    $row = xtreamImportedChannelPayloadRow($user, null);

    expect((int) $row['shift'])->toBe(0);
});
