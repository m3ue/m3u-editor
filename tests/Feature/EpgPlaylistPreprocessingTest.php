<?php

use App\Events\EpgCreated;
use App\Events\EpgDeleted;
use App\Events\EpgUpdated;
use App\Events\PlaylistCreated;
use App\Events\PlaylistDeleted;
use App\Events\PlaylistUpdated;
use App\Models\Channel;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\Playlist;
use App\Models\User;
use App\Services\EpgCacheService;
use App\Services\EpgPlaylistPreprocessor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Event::fake([
        EpgCreated::class,
        EpgDeleted::class,
        EpgUpdated::class,
        PlaylistCreated::class,
        PlaylistDeleted::class,
        PlaylistUpdated::class,
    ]);
});

test('an epg can be preprocessed using the live channels retained by a playlist', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'import_prefs' => ['preprocess' => true, 'included_group_prefixes' => ['ES|', 'LAT|']],
    ]);
    $epg = Epg::factory()->for($user)->create([
        'preprocess' => true,
        'preprocess_playlist_id' => $playlist->id,
    ]);

    Channel::factory()->for($user)->for($playlist)->create([
        'is_vod' => false,
        'stream_id' => 'es.channel',
        'stream_id_custom' => null,
        'source_id' => null,
    ]);
    Channel::factory()->for($user)->for($playlist)->create([
        'is_vod' => true,
        'stream_id' => 'vod.channel',
    ]);

    $sourcePath = Storage::disk('local')->path("epg-test-sources/{$epg->uuid}.xml");
    Storage::disk('local')->put("epg-test-sources/{$epg->uuid}.xml", xmltvFixture());

    $result = app(EpgPlaylistPreprocessor::class)->preprocess($epg, $sourcePath);
    $output = Storage::disk('local')->get($epg->file_path);

    expect($result)
        ->channels->toBe(1)
        ->programmes->toBe(1)
        ->playlist_channels->toBe(1)
        ->and($output)
        ->toContain('id="es.channel"')
        ->toContain('channel="es.channel"')
        ->not->toContain('other.channel')
        ->not->toContain('vod.channel');
});

test('a mapped channel id is preferred when the playlist channel is mapped to this epg', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'import_prefs' => ['preprocess' => true],
    ]);
    $epg = Epg::factory()->for($user)->create([
        'preprocess' => true,
        'preprocess_playlist_id' => $playlist->id,
    ]);
    $epgChannel = EpgChannel::factory()->for($user)->for($epg)->create([
        'channel_id' => 'es.channel',
    ]);

    Channel::factory()->for($user)->for($playlist)->create([
        'is_vod' => false,
        'stream_id' => 'provider-does-not-match',
        'epg_channel_id' => $epgChannel->id,
    ]);

    $sourcePath = Storage::disk('local')->path("epg-test-sources/{$epg->uuid}.xml");
    Storage::disk('local')->put("epg-test-sources/{$epg->uuid}.xml", xmltvFixture());

    $result = app(EpgPlaylistPreprocessor::class)->preprocess($epg, $sourcePath);

    expect($result['channels'])->toBe(1)
        ->and(Storage::disk('local')->get($epg->file_path))->toContain('id="es.channel"');
});

test('display name prefix filtering intersects XMLTV display names with playlist channel ids', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'import_prefs' => ['preprocess' => true],
    ]);
    $epg = Epg::factory()->for($user)->create([
        'preprocess' => true,
        'preprocess_playlist_id' => $playlist->id,
        'preprocess_display_name_filter' => true,
        'preprocess_display_name_prefixes' => ['ES|', 'lat|'],
    ]);

    foreach (['shared.channel', 'pl.channel'] as $streamId) {
        Channel::factory()->for($user)->for($playlist)->create([
            'is_vod' => false,
            'stream_id' => $streamId,
            'stream_id_custom' => null,
            'source_id' => null,
        ]);
    }

    $sourcePath = Storage::disk('local')->path("epg-test-sources/{$epg->uuid}.xml");
    Storage::disk('local')->put("epg-test-sources/{$epg->uuid}.xml", displayNamePrefixFixture());

    $result = app(EpgPlaylistPreprocessor::class)->preprocess($epg, $sourcePath);
    $output = Storage::disk('local')->get($epg->file_path);

    expect($result)
        ->channels->toBe(1)
        ->programmes->toBe(1)
        ->and($output)
        ->toContain('<display-name>ES| SHARED HD</display-name>')
        ->toContain('<title>Shared programme</title>')
        ->not->toContain('PL| SHARED HD')
        ->not->toContain('PL| POLISH HD')
        ->not->toContain('ES| NOT IN PLAYLIST');
});

test('display names do not affect the existing id filter when the option is disabled', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'import_prefs' => ['preprocess' => true],
    ]);
    $epg = Epg::factory()->for($user)->create([
        'preprocess' => true,
        'preprocess_playlist_id' => $playlist->id,
        'preprocess_display_name_filter' => false,
        'preprocess_display_name_prefixes' => ['ES|'],
    ]);

    Channel::factory()->for($user)->for($playlist)->create([
        'is_vod' => false,
        'stream_id' => 'pl.channel',
        'stream_id_custom' => null,
        'source_id' => null,
    ]);

    $sourcePath = Storage::disk('local')->path("epg-test-sources/{$epg->uuid}.xml");
    Storage::disk('local')->put("epg-test-sources/{$epg->uuid}.xml", displayNamePrefixFixture());

    $result = app(EpgPlaylistPreprocessor::class)->preprocess($epg, $sourcePath);

    expect($result['channels'])->toBe(1)
        ->and(Storage::disk('local')->get($epg->file_path))->toContain('PL| POLISH HD');
});

test('enabled display name filtering requires at least one prefix', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'import_prefs' => ['preprocess' => true],
    ]);
    $epg = Epg::factory()->for($user)->create([
        'preprocess' => true,
        'preprocess_playlist_id' => $playlist->id,
        'preprocess_display_name_filter' => true,
        'preprocess_display_name_prefixes' => [],
    ]);

    Channel::factory()->for($user)->for($playlist)->create([
        'is_vod' => false,
        'stream_id' => 'es.channel',
    ]);

    expect(fn () => app(EpgPlaylistPreprocessor::class)->preprocess($epg, '/tmp/source.xml'))
        ->toThrow(RuntimeException::class, 'Configure at least one XMLTV display-name prefix');
});

test('the epg cache reads the filtered managed file instead of the original upload', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'import_prefs' => ['preprocess' => true],
    ]);
    $epg = Epg::factory()->for($user)->create([
        'url' => null,
        'uploads' => 'epg-test-sources/full.xml',
        'preprocess' => true,
        'preprocess_playlist_id' => $playlist->id,
    ]);

    Channel::factory()->for($user)->for($playlist)->create([
        'is_vod' => false,
        'stream_id' => 'es.channel',
        'stream_id_custom' => null,
        'source_id' => null,
    ]);
    Storage::disk('local')->put($epg->uploads, xmltvFixture());

    app(EpgPlaylistPreprocessor::class)->preprocess(
        $epg,
        Storage::disk('local')->path($epg->uploads),
    );

    expect(app(EpgCacheService::class)->cacheEpgData($epg))->toBeTrue();

    $metadata = json_decode(
        Storage::disk('local')->get("epg-cache/{$epg->uuid}/v2/metadata.json"),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($metadata['total_channels'])->toBe(1)
        ->and($metadata['total_programmes'])->toBe(1);
});

test('epg preprocessing rejects an inaccessible or unsuitable playlist', function (string $scenario) {
    $user = User::factory()->create();
    $playlistUser = $scenario === 'different user' ? User::factory()->create() : $user;
    $playlist = Playlist::factory()->for($playlistUser)->create([
        'import_prefs' => ['preprocess' => $scenario !== 'disabled'],
    ]);
    $epg = Epg::factory()->for($user)->create([
        'preprocess' => true,
        'preprocess_playlist_id' => $playlist->id,
    ]);

    if ($scenario !== 'no channels') {
        Channel::factory()->for($playlistUser)->for($playlist)->create([
            'is_vod' => false,
            'stream_id' => $scenario === 'no intersection' ? 'missing.channel' : 'es.channel',
        ]);
    }

    $sourcePath = Storage::disk('local')->path("epg-test-sources/{$epg->uuid}.xml");
    Storage::disk('local')->put("epg-test-sources/{$epg->uuid}.xml", xmltvFixture());

    expect(fn () => app(EpgPlaylistPreprocessor::class)->preprocess($epg, $sourcePath))
        ->toThrow(RuntimeException::class);
})->with(['different user', 'disabled', 'no channels', 'no intersection']);

test('epg preprocessing refuses a model without a storage uuid', function () {
    $epg = Epg::factory()->make(['uuid' => null]);

    expect(fn () => app(EpgPlaylistPreprocessor::class)->preprocess($epg, '/tmp/source.xml'))
        ->toThrow(RuntimeException::class, 'must have a UUID');
});

function xmltvFixture(): string
{
    $start = now()->addHour()->format('YmdHis O');
    $stop = now()->addHours(2)->format('YmdHis O');

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<tv generator-info-name="test">
  <channel id="es.channel"><display-name>ES Channel</display-name></channel>
  <channel id="other.channel"><display-name>Other Channel</display-name></channel>
  <channel id="vod.channel"><display-name>VOD Channel</display-name></channel>
  <programme start="{$start}" stop="{$stop}" channel="es.channel"><title>Included</title></programme>
  <programme start="{$start}" stop="{$stop}" channel="other.channel"><title>Excluded</title></programme>
  <programme start="{$start}" stop="{$stop}" channel="vod.channel"><title>VOD</title></programme>
</tv>
XML;
}

function displayNamePrefixFixture(): string
{
    $start = now()->addHour()->format('YmdHis O');
    $stop = now()->addHours(2)->format('YmdHis O');

    return <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<tv>
  <programme start="{$start}" stop="{$stop}" channel="shared.channel"><title>Shared programme</title></programme>
  <channel id="shared.channel"><display-name>ES| SHARED HD</display-name></channel>
  <channel id="shared.channel"><display-name>PL| SHARED HD</display-name></channel>
  <channel id="pl.channel"><display-name>PL| POLISH HD</display-name></channel>
  <channel id="not-in-playlist"><display-name>ES| NOT IN PLAYLIST</display-name></channel>
  <programme start="{$start}" stop="{$stop}" channel="pl.channel"><title>Polish programme</title></programme>
  <programme start="{$start}" stop="{$stop}" channel="not-in-playlist"><title>Unrelated programme</title></programme>
</tv>
XML;
}
