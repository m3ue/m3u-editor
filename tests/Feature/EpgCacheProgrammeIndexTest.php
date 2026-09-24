<?php

use App\Events\EpgCreated;
use App\Events\PlaylistCreated;
use App\Models\Channel;
use App\Models\DvrSetting;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\Playlist;
use App\Models\User;
use App\Services\EpgCacheService;
use App\Services\EpgCacheStorage;
use App\Services\EpgProgrammeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([EpgCreated::class, PlaylistCreated::class]);
    Storage::fake('local');
});

/**
 * Builds a gzipped XMLTV document whose <programme> elements are emitted in the
 * given order (so tests can exercise contiguous and interleaved channel
 * layouts), then runs the real cache generation against the new SQLite store.
 *
 * Each programme spec is {channel, title, startHour, xml?} where `xml` is an
 * optional raw fragment injected inside the <programme> element.
 *
 * @param  list<array{channel: string, title: string, startHour: int, xml?: string}>  $programmes
 * @return array{epg: Epg, playlist: Playlist, date: string}
 */
function cacheXmltvForIndex(array $channelIds, array $programmes): array
{
    $user = User::factory()->create();
    $playlist = Playlist::withoutEvents(fn () => Playlist::factory()->for($user)->create(['dummy_epg' => false]));
    $epg = Epg::withoutEvents(fn () => Epg::factory()->for($user)->create(['url' => 'https://example.com/index.xml']));

    foreach ($channelIds as $channelId) {
        $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
            'channel_id' => $channelId,
            'display_name' => $channelId,
        ]);
        Channel::factory()->for($user)->for($playlist)->create([
            'enabled' => true,
            'is_vod' => false,
            'epg_channel_id' => $epgChannel->id,
        ]);
    }

    $channelXml = collect($channelIds)
        ->map(fn ($id) => "  <channel id=\"{$id}\"><display-name>{$id}</display-name></channel>")
        ->implode("\n");

    $programmeXml = collect($programmes)
        ->map(function ($p) {
            $start = now()->startOfDay()->addHours($p['startHour'])->format('YmdHis O');
            $stop = now()->startOfDay()->addHours($p['startHour'] + 1)->format('YmdHis O');

            return "  <programme start=\"{$start}\" stop=\"{$stop}\" channel=\"{$p['channel']}\">\n".
                "    <title>{$p['title']}</title>\n".
                ($p['xml'] ?? '').
                '  </programme>';
        })
        ->implode("\n");

    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<tv>\n{$channelXml}\n{$programmeXml}\n</tv>";
    Storage::disk('local')->put($epg->file_path, gzencode($xml));

    expect(app(EpgCacheService::class)->cacheEpgData($epg))->toBeTrue();

    return ['epg' => $epg, 'playlist' => $playlist, 'date' => now()->format('Y-m-d')];
}

it('writes a single programmes.sqlite and no legacy jsonl or staging artifacts', function () {
    ['epg' => $epg] = cacheXmltvForIndex(
        ['channel.a', 'channel.b'],
        [
            ['channel' => 'channel.a', 'title' => 'A One', 'startHour' => 1],
            ['channel' => 'channel.a', 'title' => 'A Two', 'startHour' => 2],
            ['channel' => 'channel.b', 'title' => 'B One', 'startHour' => 1],
        ],
    );

    $cacheRoot = "epg-cache/{$epg->uuid}/v2";
    $activeDirectory = app(EpgCacheStorage::class)->resolve($epg);
    $files = Storage::disk('local')->allFiles($cacheRoot);

    expect($files)->toContain("{$activeDirectory}/programmes.sqlite")
        ->and(collect($files)->filter(fn ($f) => str_contains($f, '.jsonl'))->all())->toBe([])
        ->and(collect($files)->filter(fn ($f) => str_contains($f, '.building'))->all())->toBe([]);
});

it('returns only the requested channel, ordered by start', function () {
    ['epg' => $epg, 'date' => $date] = cacheXmltvForIndex(
        ['channel.a', 'channel.b'],
        [
            ['channel' => 'channel.a', 'title' => 'A One', 'startHour' => 1],
            ['channel' => 'channel.b', 'title' => 'B Late', 'startHour' => 5],
            ['channel' => 'channel.b', 'title' => 'B Early', 'startHour' => 1],
        ],
    );

    $programmes = app(EpgCacheService::class)->getCachedProgrammes($epg, $date, ['channel.b']);

    expect(array_keys($programmes))->toBe(['channel.b'])
        ->and(collect($programmes['channel.b'])->pluck('title')->all())->toBe(['B Early', 'B Late']);
});

it('resolves interleaved channels and the range reader agrees', function () {
    ['epg' => $epg, 'date' => $date] = cacheXmltvForIndex(
        ['channel.a', 'channel.b'],
        [
            ['channel' => 'channel.b', 'title' => 'B One', 'startHour' => 1],
            ['channel' => 'channel.a', 'title' => 'A One', 'startHour' => 1],
            ['channel' => 'channel.b', 'title' => 'B Two', 'startHour' => 2],
            ['channel' => 'channel.a', 'title' => 'A Two', 'startHour' => 2],
        ],
    );

    $service = app(EpgCacheService::class);

    expect(collect($service->getCachedProgrammes($epg, $date, ['channel.b'])['channel.b'])->pluck('title')->all())
        ->toBe(['B One', 'B Two']);

    $range = $service->getCachedProgrammesRange($epg, $date, $date, ['channel.a']);
    expect(collect($range['channel.a'])->pluck('title')->all())->toBe(['A One', 'A Two']);
});

it('round-trips the full programme shape through dehydrate and hydrate', function () {
    ['epg' => $epg, 'date' => $date] = cacheXmltvForIndex(
        ['channel.a'],
        [
            [
                'channel' => 'channel.a',
                'title' => 'Rich One',
                'startHour' => 1,
                'xml' => "    <sub-title>Pilot</sub-title>\n".
                    "    <desc>The big one</desc>\n".
                    "    <category>News</category>\n".
                    "    <icon src=\"http://img/poster.png\"/>\n".
                    "    <episode-num system=\"onscreen\">S01E02</episode-num>\n".
                    "    <new/>\n",
            ],
            ['channel' => 'channel.a', 'title' => 'Bare Two', 'startHour' => 2],
        ],
    );

    $programmes = app(EpgCacheService::class)->getCachedProgrammes($epg, $date, ['channel.a']);
    [$rich, $bare] = $programmes['channel.a'];

    expect($rich['title'])->toBe('Rich One')
        ->and($rich['subtitle'])->toBe('Pilot')
        ->and($rich['desc'])->toBe('The big one')
        ->and($rich['category'])->toBe('News')
        ->and($rich['icon'])->toBe('http://img/poster.png')
        ->and($rich['new'])->toBeTrue()
        ->and($rich['episode_nums'])->not->toBe([])
        ->and($rich['channel'])->toBe('channel.a')
        ->and($rich['start'])->toEndWith('.000000Z');

    // A programme with only a title still comes back with every canonical key.
    expect(array_keys($bare))->toEqualCanonicalizing(array_keys(EpgProgrammeStore::EMPTY_PROGRAMME))
        ->and($bare['desc'])->toBe('')
        ->and($bare['new'])->toBeFalse()
        ->and($bare['images'])->toBe([])
        ->and($bare['production_year'])->toBeNull();
});

it('still reads a legacy jsonl cache that has no sqlite store', function () {
    $user = User::factory()->create();
    Playlist::factory()->for($user)->create(['dummy_epg' => false]);
    $epg = Epg::factory()->for($user)->create(['is_cached' => true]);
    $date = now()->format('Y-m-d');

    $dir = "epg-cache/{$epg->uuid}/v2";
    Storage::disk('local')->put("{$dir}/metadata.json", json_encode(['cache_version' => 'v2']));
    Storage::disk('local')->put("{$dir}/programmes-{$date}.jsonl", collect([
        ['channel' => 'legacy.a', 'programme' => ['title' => 'Legacy A', 'start' => '2026-01-01T00:00:00Z']],
        ['channel' => 'legacy.b', 'programme' => ['title' => 'Legacy B', 'start' => '2026-01-01T00:00:00Z']],
    ])->map(fn ($r) => json_encode($r))->implode("\n"));

    $programmes = app(EpgCacheService::class)->getCachedProgrammes($epg, $date, ['legacy.b']);

    expect($programmes)->toBe(['legacy.b' => [['title' => 'Legacy B', 'start' => '2026-01-01T00:00:00Z']]]);
});

it('populates epg_programmes for DVR from the sqlite store', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['dummy_epg' => false]);
    $epg = Epg::factory()->for($user)->create(['url' => 'https://example.com/index.xml']);
    DvrSetting::factory()->enabled()->for($user)->for($playlist)->create();

    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
        'channel_id' => 'dvr.a',
        'display_name' => 'dvr.a',
    ]);
    Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
    ]);

    $start = now()->addHours(2);
    $stop = now()->addHours(3);
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<tv>\n".
        "  <channel id=\"dvr.a\"><display-name>dvr.a</display-name></channel>\n".
        "  <programme start=\"{$start->format('YmdHis O')}\" stop=\"{$stop->format('YmdHis O')}\" channel=\"dvr.a\">\n".
        "    <title>DVR Show</title>\n    <desc>Body</desc>\n  </programme>\n</tv>";
    Storage::disk('local')->put($epg->file_path, gzencode($xml));

    expect(app(EpgCacheService::class)->cacheEpgData($epg))->toBeTrue();

    $row = EpgProgramme::where('epg_id', $epg->id)->where('epg_channel_id', 'dvr.a')->first();
    expect($row)->not->toBeNull()
        ->and($row->title)->toBe('DVR Show')
        ->and($row->description)->toBe('Body');
});
