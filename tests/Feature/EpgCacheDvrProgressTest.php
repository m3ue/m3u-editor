<?php

use App\Filament\Resources\Epgs\Pages\ListEpgs;
use App\Jobs\GenerateEpgCache;
use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\DvrSetting;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\EpgProgramme;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\User;
use App\Services\EpgCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Bus::fake();
    Http::preventStrayRequests();

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create(['dummy_epg' => false]);
    $this->epg = Epg::factory()->for($this->user)->create(['url' => 'https://example.com/dvr.xml']);

    $epgChannel = EpgChannel::factory()->for($this->user)->for($this->epg)->create([
        'channel_id' => 'dvr.a',
        'display_name' => 'dvr.a',
    ]);
    $this->channel = Channel::factory()->for($this->user)->for($this->playlist)->create([
        'enabled' => true,
        'is_vod' => false,
        'epg_channel_id' => $epgChannel->id,
    ]);

    $start = now()->addHours(2);
    $stop = now()->addHours(3);
    $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<tv>\n".
        "  <channel id=\"dvr.a\"><display-name>dvr.a</display-name></channel>\n".
        "  <programme start=\"{$start->format('YmdHis O')}\" stop=\"{$stop->format('YmdHis O')}\" channel=\"dvr.a\">\n".
        "    <title>DVR Show</title>\n  </programme>\n</tv>";
    Storage::disk('local')->put($this->epg->file_path, gzencode($xml));
});

it('reports no DVR when no consuming playlist has DVR enabled', function () {
    expect($this->epg->hasDvrEnabled())->toBeFalse();

    DvrSetting::factory()->for($this->user)->for($this->playlist)->create(['enabled' => false]);

    expect($this->epg->hasDvrEnabled())->toBeFalse();
});

it('reports DVR for a playlist with DVR enabled', function () {
    DvrSetting::factory()->enabled()->for($this->user)->for($this->playlist)->create();

    expect($this->epg->hasDvrEnabled())->toBeTrue();
});

it('reports DVR for a custom playlist that includes a mapped channel', function () {
    $customPlaylist = CustomPlaylist::factory()->for($this->user)->create();
    $customPlaylist->channels()->attach($this->channel->id);
    DvrSetting::factory()->enabled()->for($this->user)->create([
        'playlist_id' => null,
        'custom_playlist_id' => $customPlaylist->id,
    ]);

    expect($this->epg->hasDvrEnabled())->toBeTrue();
});

it('reports DVR for a merged playlist containing the mapped playlist', function () {
    $mergedPlaylist = MergedPlaylist::factory()->for($this->user)->create();
    $mergedPlaylist->playlists()->attach($this->playlist->id);
    DvrSetting::factory()->enabled()->for($this->user)->create([
        'playlist_id' => null,
        'merged_playlist_id' => $mergedPlaylist->id,
    ]);

    expect($this->epg->hasDvrEnabled())->toBeTrue();
});

it('resolves has_dvr for many EPGs in a single query via withHasDvr', function () {
    DvrSetting::factory()->enabled()->for($this->user)->for($this->playlist)->create();
    $otherEpg = Epg::factory()->for($this->user)->create();

    DB::enableQueryLog();
    $epgs = Epg::query()->withHasDvr()->get()->keyBy('id');
    $queryCount = count(DB::getQueryLog());

    expect($epgs[$this->epg->id]->hasDvrEnabled())->toBeTrue()
        ->and($epgs[$otherEpg->id]->hasDvrEnabled())->toBeFalse()
        ->and(count(DB::getQueryLog()))->toBe($queryCount);
});

it('resolves has_dvr as false for every EPG when no DVR is enabled', function () {
    $epg = Epg::query()->withHasDvr()->find($this->epg->id);

    expect($epg->hasDvrEnabled())->toBeFalse();
});

it('only reports 100% cache progress after DVR programmes are populated', function () {
    DvrSetting::factory()->enabled()->for($this->user)->for($this->playlist)->create();

    $progressSnapshots = [];
    Epg::updated(function (Epg $epg) use (&$progressSnapshots): void {
        if ($epg->wasChanged('cache_progress')) {
            $progressSnapshots[] = [
                'progress' => (int) $epg->cache_progress,
                'dvr_rows' => EpgProgramme::where('epg_id', $epg->id)->count(),
            ];
        }
    });

    expect(app(EpgCacheService::class)->cacheEpgData($this->epg))->toBeTrue();

    $completed = collect($progressSnapshots)->where('progress', 100);
    expect($completed)->not->toBeEmpty()
        ->and($completed->every(fn (array $snapshot): bool => $snapshot['dvr_rows'] > 0))->toBeTrue()
        ->and($this->epg->fresh()->cache_progress)->toEqual(100);
});

it('records the cache time when the cache job completes', function () {
    (new GenerateEpgCache($this->epg->uuid))->handle(app(EpgCacheService::class));

    $epg = $this->epg->fresh();
    expect($epg->is_cached)->toBeTrue()
        ->and($epg->cache_time)->not->toBeNull();
});

it('shows the Has DVR and Cache Time columns on the EPG table', function () {
    DvrSetting::factory()->enabled()->for($this->user)->for($this->playlist)->create();
    $otherEpg = Epg::factory()->for($this->user)->create(['cache_time' => 75]);

    $this->actingAs($this->user);

    Livewire::test(ListEpgs::class)
        ->loadTable()
        ->assertTableColumnExists('has_dvr')
        ->assertTableColumnStateSet('has_dvr', true, $this->epg)
        ->assertTableColumnStateSet('has_dvr', false, $otherEpg)
        ->assertTableColumnFormattedStateSet('cache_time', '00:01:15', $otherEpg);
});
