<?php

/**
 * Covers two related bugs in a single playlist VOD .strm sync batch
 * (SyncVodStrmFiles::syncChannels()):
 *
 * - A channel whose effective sync location differs from the batch's global
 *   default (a per-channel Location Override) was looked up in a mapping
 *   cache that was only ever bulk-loaded for the global location, so its
 *   existing mapping was never found - repeat syncs created a duplicate
 *   mapping/file instead of reusing or renaming the original.
 * - A shared $nfoService instance, lazily created on the first NFO-enabled
 *   channel in a chunk, was never re-checked against each later channel's
 *   own resolved `generate_nfo` setting, so an NFO-disabled channel
 *   processed after an NFO-enabled one still got an .nfo file written.
 */

use App\Jobs\SyncVodStrmFiles;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\StreamFileSetting;
use App\Models\StrmFileMapping;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();
    $this->settings = app(GeneralSettings::class);

    $this->globalDir = sys_get_temp_dir().'/strm-global-'.uniqid();
    $this->overrideDir = sys_get_temp_dir().'/strm-override-'.uniqid();
    mkdir($this->globalDir, 0777, true);
    mkdir($this->overrideDir, 0777, true);
});

afterEach(function () {
    foreach ([$this->globalDir, $this->overrideDir] as $dir) {
        if (is_dir($dir)) {
            foreach (glob($dir.'/*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($dir);
        }
    }
});

function runVodStrmBatch(Playlist $playlist, GeneralSettings $settings): void
{
    (new SyncVodStrmFiles(
        notify: false,
        playlist_id: $playlist->id,
        batchOffset: 0,
        totalBatches: 1,
        currentBatch: 1,
    ))->handle($settings);
}

it('reuses the same mapping across repeated syncs for a channel with a Location Override', function () {
    $setting = StreamFileSetting::factory()->for($this->user)->create([
        'type' => 'vod',
        'enabled' => true,
        'location' => $this->globalDir,
        'url_type' => 'original',
        'path_structure' => [],
        'generate_nfo' => false,
    ]);

    // Configured as the account-wide default profile, so the bulk mapping
    // cache (built from the global default location) is actually exercised -
    // without this, the Location Override below never collides with it.
    $this->settings = Mockery::mock(GeneralSettings::class);
    $this->settings->default_vod_stream_file_setting_id = $setting->id;

    $alpha = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Alpha',
        'name' => 'Alpha',
        'url' => 'http://provider.test/alpha.mkv',
        'container_extension' => 'mkv',
        'stream_file_setting_id' => $setting->id,
        'sync_location' => $this->overrideDir,
    ]);
    $beta = Channel::factory()->for($this->playlist)->for($this->user)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Beta',
        'name' => 'Beta',
        'url' => 'http://provider.test/beta.mkv',
        'container_extension' => 'mkv',
        'stream_file_setting_id' => $setting->id,
        'sync_location' => $this->overrideDir,
    ]);

    runVodStrmBatch($this->playlist, $this->settings);

    expect(StrmFileMapping::where('syncable_id', $alpha->id)->count())->toBe(1);

    // Repeat sync with nothing changed: must not create a second mapping.
    runVodStrmBatch($this->playlist, $this->settings);

    expect(StrmFileMapping::where('syncable_id', $alpha->id)->count())->toBe(1)
        ->and(StrmFileMapping::where('syncable_id', $beta->id)->count())->toBe(1);

    // A Custom Title edit must rename the existing file, not leave the old
    // one behind and add a third mapping row.
    $alpha->update(['title_custom' => 'Alpha Revised']);
    runVodStrmBatch($this->playlist, $this->settings);

    expect(StrmFileMapping::where('syncable_id', $alpha->id)->count())->toBe(1)
        ->and(file_exists($this->overrideDir.'/Alpha.strm'))->toBeFalse()
        ->and(file_exists($this->overrideDir.'/Alpha Revised.strm'))->toBeTrue();
});

it('does not write an NFO for an NFO-disabled channel processed after an NFO-enabled one', function () {
    $settingWithNfo = StreamFileSetting::factory()->for($this->user)->create([
        'type' => 'vod',
        'enabled' => true,
        'location' => $this->globalDir,
        'url_type' => 'original',
        'path_structure' => [],
        'generate_nfo' => true,
    ]);
    $settingWithoutNfo = StreamFileSetting::factory()->for($this->user)->create([
        'type' => 'vod',
        'enabled' => true,
        'location' => $this->globalDir,
        'url_type' => 'original',
        'path_structure' => [],
        'generate_nfo' => false,
    ]);

    // Created (and thus chunk-ordered) before the NFO-disabled channel.
    Channel::factory()->for($this->playlist)->for($this->user)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'With NFO',
        'name' => 'With NFO',
        'url' => 'http://provider.test/with-nfo.mkv',
        'container_extension' => 'mkv',
        'stream_file_setting_id' => $settingWithNfo->id,
    ]);
    Channel::factory()->for($this->playlist)->for($this->user)->create([
        'enabled' => true,
        'is_vod' => true,
        'title' => 'Without NFO',
        'name' => 'Without NFO',
        'url' => 'http://provider.test/without-nfo.mkv',
        'container_extension' => 'mkv',
        'stream_file_setting_id' => $settingWithoutNfo->id,
    ]);

    runVodStrmBatch($this->playlist, $this->settings);

    expect(file_exists($this->globalDir.'/With NFO.nfo'))->toBeTrue()
        ->and(file_exists($this->globalDir.'/Without NFO.nfo'))->toBeFalse();
});
