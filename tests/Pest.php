<?php

use App\Models\Epg;
use App\Models\MediaServerIntegration;
use App\Services\EpgProgrammeStore;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Seed an EPG's on-disk programme cache the way production writes it: a real
 * `programmes.sqlite` built through {@see EpgProgrammeStore}, plus a v2
 * `metadata.json`. Use this instead of hand-writing `programmes-{date}.jsonl`
 * fixtures so tests exercise the SQLite read path that `EpgCacheService` now
 * takes.
 *
 * Each entry is `[$epgChannelId, $programme]`; `$programme` needs at least a
 * `title` and a parseable `start` (and normally `stop`). The local `Y-m-d`
 * bucket and the start/stop unix timestamps are derived from those, mirroring
 * the XMLTV parser. Keys outside the canonical set (e.g. `id`, `lang`) are
 * stored and returned verbatim, matching the old JSONL behaviour. Call once
 * per EPG with every programme - a second call rebuilds the file from scratch.
 *
 * @param  iterable<array{0: string, 1: array<string, mixed>}>  $entries
 */
function seedEpgProgrammeCache(Epg $epg, iterable $entries): void
{
    $cacheDirectory = "epg-cache/{$epg->uuid}/v2";

    Storage::disk('local')->put("{$cacheDirectory}/metadata.json", json_encode([
        'cache_created' => time(),
        'cache_version' => 'v2',
    ], JSON_THROW_ON_ERROR));

    $store = new EpgProgrammeStore;
    $store->beginWrite(Storage::disk('local')->path("{$cacheDirectory}/programmes.sqlite"));

    try {
        foreach ($entries as [$channelId, $programme]) {
            $start = Carbon::parse($programme['start']);
            $stop = empty($programme['stop']) ? null : Carbon::parse($programme['stop']);

            $store->insert(
                $channelId,
                $start->format('Y-m-d'),
                $start->getTimestamp(),
                $stop?->getTimestamp(),
                $programme,
            );
        }

        $store->finish();
    } catch (Throwable $e) {
        $store->discard();
        throw $e;
    }
}

function createEligiblePlexDvrIntegration(int $userId): MediaServerIntegration
{
    return MediaServerIntegration::withoutEvents(function () use ($userId) {
        return MediaServerIntegration::create([
            'name' => 'Managed Plex',
            'type' => 'plex',
            'host' => 'plex.example.com',
            'port' => 32400,
            'ssl' => false,
            'api_key' => 'test-token',
            'enabled' => true,
            'user_id' => $userId,
            'plex_management_enabled' => true,
            'plex_dvr_id' => 1,
            'plex_dvr_tuners' => [['device_key' => 'dev1', 'playlist_uuid' => 'uuid1']],
        ]);
    });
}
