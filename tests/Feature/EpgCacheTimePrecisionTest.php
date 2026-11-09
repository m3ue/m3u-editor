<?php

use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\EpgCacheService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    $this->originalTimezone = date_default_timezone_get();
    config(['app.timezone' => 'UTC']);
    date_default_timezone_set('UTC');
});

afterEach(function () {
    date_default_timezone_set($this->originalTimezone);
});

/**
 * Seeds an EPG + playlist + mapped channel, caches the given raw XMLTV body and
 * returns the pieces needed to query the cache back out.
 */
function cacheRawXmltv(string $channelId, string $xmlBody): array
{
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create(['dummy_epg' => false]);
    $epg = Epg::factory()->for($user)->create(['url' => 'https://example.com/precision.xml']);

    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
        'channel_id' => $channelId,
        'display_name' => $channelId,
    ]);
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
    ]);

    Storage::disk('local')->put($epg->file_path, gzencode($xmlBody));

    expect(app(EpgCacheService::class)->cacheEpgData($epg))->toBeTrue();

    return ['epg' => $epg, 'playlist' => $playlist, 'channel' => $channel];
}

it('keeps minute-precision xmltv programmes instead of discarding them', function () {
    $xml = <<<'XML'
<?xml version="1.0"?>
<tv>
  <channel id="minute.test"><display-name>Minute channel</display-name></channel>
  <programme channel="minute.test" start="202610220100 +0000" stop="202610220200 +0000"><title>Minute programme</title></programme>
</tv>
XML;

    ['epg' => $epg] = cacheRawXmltv('minute.test', $xml);

    $programmes = app(EpgCacheService::class)->getCachedProgrammes($epg, '2026-10-22', ['minute.test']);

    expect($programmes['minute.test'] ?? [])->toHaveCount(1)
        ->and($programmes['minute.test'][0]['title'])->toBe('Minute programme')
        ->and(Carbon::parse($programmes['minute.test'][0]['start']))->toEqual(Carbon::parse('2026-10-22 01:00:00', 'UTC'))
        ->and(Carbon::parse($programmes['minute.test'][0]['stop']))->toEqual(Carbon::parse('2026-10-22 02:00:00', 'UTC'));
});

it('buckets a negative-offset programme under the app-timezone date its utc instant falls on', function () {
    // Source-local date is 2026-10-21, but the UTC instant (2026-10-22 00:00) falls on the 22nd.
    $xml = <<<'XML'
<?xml version="1.0"?>
<tv>
  <channel id="offset.test"><display-name>Offset channel</display-name></channel>
  <programme channel="offset.test" start="20261021200000 -0400" stop="20261021210000 -0400"><title>Midnight programme</title></programme>
</tv>
XML;

    ['epg' => $epg] = cacheRawXmltv('offset.test', $xml);

    $service = app(EpgCacheService::class);

    $onUtcDate = $service->getCachedProgrammes($epg, '2026-10-22', ['offset.test']);
    $onSourceLocalDate = $service->getCachedProgrammes($epg, '2026-10-21', ['offset.test']);

    expect($onUtcDate['offset.test'] ?? [])->toHaveCount(1)
        ->and($onUtcDate['offset.test'][0]['title'])->toBe('Midnight programme')
        ->and($onSourceLocalDate['offset.test'] ?? [])->toBe([]);
});
