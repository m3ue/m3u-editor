<?php

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgMap;
use App\Models\Group;
use App\Models\Job;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Support\Facades\Process;

beforeEach(function () {
    Process::fake([
        '*rev-parse*' => Process::result("abc1234\n"),
        '*' => Process::result(''),
    ]);

    $this->baselinePath = sys_get_temp_dir().'/epg-benchmark-baseline-'.uniqid().'.json';
});

afterEach(function () {
    @unlink($this->baselinePath);
});

/**
 * @return array<string, mixed>
 */
function runEpgBenchmark(array $options = []): array
{
    $path = sys_get_temp_dir().'/epg-benchmark-run-'.uniqid().'.json';

    test()->artisan('epg:benchmark-mapping', [
        '--channels' => 60,
        '--epg-channels' => 400,
        '--iterations' => 1,
        '--json' => $path,
        ...$options,
    ])->assertSuccessful();

    $results = json_decode(file_get_contents($path), true);
    @unlink($path);

    return $results;
}

it('benchmarks the real matcher on synthetic data and leaves nothing behind', function () {
    $results = runEpgBenchmark(['--iterations' => 2]);

    expect($results['revision'])->toBe('abc1234')
        ->and($results['runs'])->toHaveCount(2)
        ->and($results['mappings'])->toHaveCount(60)
        ->and($results['deterministic'])->toBeTrue()
        ->and(collect($results['quality'])->sum('channels'))->toBe(60);

    // The exact lookups (channel_id, name, callsign) always resolve, so
    // these prove the chunk job really ran against the seeded guide.
    foreach (['tvg_id', 'exact_name', 'callsign'] as $pattern) {
        expect($results['quality'][$pattern]['correct'])->toBe($results['quality'][$pattern]['channels']);
    }

    expect(User::query()->where('name', 'EPG mapping benchmark')->exists())->toBeFalse()
        ->and(EpgChannel::query()->count())->toBe(0)
        ->and(Channel::query()->count())->toBe(0)
        ->and(Job::query()->where('title', 'like', '%EPG mapping benchmark%')->exists())->toBeFalse();
});

it('produces the same mappings for the same seed', function () {
    $first = runEpgBenchmark();
    $second = runEpgBenchmark();
    $otherSeed = runEpgBenchmark(['--seed' => 7]);

    expect($second['mappings_hash'])->toBe($first['mappings_hash'])
        ->and($otherSeed['mappings_hash'])->not->toBe($first['mappings_hash']);
});

it('reports identical mappings when compared with a matching baseline', function () {
    $baseline = runEpgBenchmark();

    // Runs this small are timing noise (especially on a busy parallel CI
    // runner), so pin the baseline's time high enough that this run can
    // never read as a slowdown - only the mapping comparison is under test.
    $baseline['summary']['median_seconds'] = 3600;
    file_put_contents($this->baselinePath, json_encode($baseline));

    $this->artisan('epg:benchmark-mapping', [
        '--channels' => 60,
        '--epg-channels' => 400,
        '--iterations' => 1,
        '--compare' => $this->baselinePath,
    ])
        ->expectsOutputToContain('Mappings are identical to the baseline')
        ->doesntExpectOutputToContain('slower than the baseline')
        ->assertSuccessful();
});

it('flags a slowdown and changed mappings against a baseline', function () {
    $baseline = runEpgBenchmark();

    // Pretend the baseline was ten times faster and mapped one channel
    // somewhere else, so this run reads as a slowdown with a mismatch.
    $baseline['summary']['median_seconds'] /= 10;
    $baseline['mappings']['bench-000001'] = 'Somewhere.else';
    file_put_contents($this->baselinePath, json_encode($baseline));

    $this->artisan('epg:benchmark-mapping', [
        '--channels' => 60,
        '--epg-channels' => 400,
        '--iterations' => 1,
        '--compare' => $this->baselinePath,
    ])
        ->expectsOutputToContain('slower than the baseline')
        ->expectsOutputToContain('1 channel(s) mapped differently from the baseline')
        ->assertSuccessful();
});

it('benchmarks an existing map without touching it', function () {
    $user = User::factory()->create();
    $epg = Epg::withoutEvents(fn () => Epg::factory()->for($user)->create());
    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create());
    $mappedGroup = Group::factory()->for($playlist)->for($user)->create();
    $otherGroup = Group::factory()->for($playlist)->for($user)->create();

    EpgChannel::factory()->for($epg)->for($user)->create([
        'name' => 'Sports Central',
        'display_name' => 'Sports Central',
        'channel_id' => 'sports-central.us',
    ]);

    $channel = fn (Group $group, string $sourceId, string $name) => Channel::factory()
        ->for($playlist)
        ->for($user)
        ->for($group)
        ->create(['source_id' => $sourceId, 'name' => $name, 'title' => $name, 'is_vod' => false]);

    $channel($mappedGroup, 'in-group', 'Sports Central HD');
    $channel($mappedGroup, 'no-match', 'Nothing Like It');
    $channel($otherGroup, 'other-group', 'Sports Central HD');

    $map = EpgMap::factory()->create([
        'epg_id' => $epg->id,
        'playlist_id' => $playlist->id,
        'user_id' => $user->id,
        'group_ids' => [$mappedGroup->id],
        'settings' => ['remove_quality_indicators' => true],
        'processing' => false,
        'progress' => 100,
    ]);

    $results = runEpgBenchmark(['--map' => $map->id]);

    expect($results['options']['map'])->toBe($map->id)
        ->and($results['options']['settings']['remove_quality_indicators'])->toBeTrue()
        ->and($results['quality'])->toBeNull()
        ->and($results['mappings'])->toBe(['in-group' => 'sports-central.us', 'no-match' => null])
        ->and($results['mapped'])->toBe(1);

    expect(EpgMap::query()->count())->toBe(1)
        ->and($map->fresh())
        ->progress->toEqual(100)
        ->processing->toBeFalse();
});

it('fails for an unknown map', function () {
    $this->artisan('epg:benchmark-mapping', ['--map' => 999999])
        ->expectsOutputToContain('EPG map 999999 not found')
        ->assertFailed();
});

it('rejects settings that are not a JSON object', function () {
    $this->artisan('epg:benchmark-mapping', ['--settings' => 'not json'])
        ->expectsOutputToContain('--settings must be a JSON object')
        ->assertFailed();
});
