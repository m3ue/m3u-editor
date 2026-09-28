<?php

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\Vods\Pages\ListVod;
use App\Filament\Resources\Vods\VodResource;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Settings\GeneralSettings;
use Filament\Actions\ActionGroup;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Bind a Mockery-mocked GeneralSettings with the requested `enable_cache` value.
 */
function setEnableCacheForChannelTest(bool $value): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->enable_cache = $value;
    app()->instance(GeneralSettings::class, $mock);
}

beforeEach(function () {
    setEnableCacheForChannelTest(true);
    Bus::fake();
    Storage::fake(CachedContentFile::DISK);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->playlist = Playlist::factory()->for($this->user)->create();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function makeVodForCacheNow(User $user, Playlist $playlist, array $attributes = []): Channel
{
    return Channel::factory()->for($user)->for($playlist)->create(array_merge([
        'is_vod' => true,
        'tmdb_id' => fake()->unique()->numberBetween(1000, 999999),
        'title' => 'Cacheable',
        'url' => 'https://example.com/cacheable.mp4',
    ], $attributes));
}

it('renders the VOD list page with the Cached column when caching is on', function () {
    makeVodForCacheNow($this->user, $this->playlist);

    Livewire::test(ListVod::class)
        ->assertOk()
        ->loadTable()
        ->assertTableColumnVisible('is_cached');
});

it('hides the Cached column when caching is off', function () {
    setEnableCacheForChannelTest(false);
    makeVodForCacheNow($this->user, $this->playlist);

    Livewire::test(ListVod::class)
        ->loadTable()
        ->assertTableColumnHidden('is_cached');
});

it('places Cache Now directly above Delete in the row action group', function () {
    $group = collect(VodResource::getTableActions())->first(fn ($action) => $action instanceof ActionGroup);
    $names = array_map(fn ($action) => $action->getName(), $group->getActions());

    expect(array_search('cache_now', $names, true))->toBe(array_search('delete', $names, true) - 1);
});

it('shows Cache Now on a VOD channel with a cacheable URL', function () {
    $channel = makeVodForCacheNow($this->user, $this->playlist);

    Livewire::test(ListVod::class)->assertTableActionVisible('cache_now', $channel);
});

it('hides Cache Now when caching is off', function () {
    setEnableCacheForChannelTest(false);
    $channel = makeVodForCacheNow($this->user, $this->playlist);

    Livewire::test(ListVod::class)->assertTableActionHidden('cache_now', $channel);
});

it('hides Cache Now when the channel has no URL or an HLS URL', function (string $url) {
    $channel = makeVodForCacheNow($this->user, $this->playlist, ['url' => $url]);

    Livewire::test(ListVod::class)->assertTableActionHidden('cache_now', $channel);
})->with(['', 'https://example.com/movie/index.m3u8']);

it('queues a download when Cache Now is clicked', function () {
    $channel = makeVodForCacheNow($this->user, $this->playlist);

    Livewire::test(ListVod::class)
        ->callAction(TestAction::make('cache_now')->table($channel))
        ->assertNotified('Cache download queued');

    Bus::assertDispatched(DownloadCachedContentFile::class);
    expect($channel->cachedContentFile()->first()->status)->toBe(CachedContentFileStatus::Pending);
});

it('says "Already cached" when a playable file exists', function () {
    $channel = makeVodForCacheNow($this->user, $this->playlist);
    $file = CachedContentFile::factory()->completed()->forItem($channel)->create();
    Storage::disk(CachedContentFile::DISK)->put($file->file_path, 'bytes');

    Livewire::test(ListVod::class)
        ->callAction(TestAction::make('cache_now')->table($channel))
        ->assertNotified('Already cached');

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('says "Already queued for caching" while a download is pending or running', function (CachedContentFileStatus $status) {
    $channel = makeVodForCacheNow($this->user, $this->playlist);
    CachedContentFile::factory()->forItem($channel)->create(['status' => $status]);

    Livewire::test(ListVod::class)
        ->callAction(TestAction::make('cache_now')->table($channel))
        ->assertNotified('Already queued for caching');
})->with([CachedContentFileStatus::Pending, CachedContentFileStatus::Downloading]);

it('re-queues a failed download from Cache Now', function () {
    $channel = makeVodForCacheNow($this->user, $this->playlist);
    $file = CachedContentFile::factory()->failed()->forItem($channel)->create();

    Livewire::test(ListVod::class)
        ->callAction(TestAction::make('cache_now')->table($channel))
        ->assertNotified('Cache download queued');

    expect($file->fresh()->status)->toBe(CachedContentFileStatus::Pending);
});

it('includes the channel title in the Cache Now modal description', function () {
    $channel = makeVodForCacheNow($this->user, $this->playlist, ['title' => 'Unique Movie Title']);

    Livewire::test(ListVod::class)
        ->mountAction(TestAction::make('cache_now')->table($channel))
        ->assertMountedActionModalSee('Unique Movie Title');
});

// --- Channel::isCached() ---

it('isCached() is true only for a Completed row of this channel', function () {
    $channel = makeVodForCacheNow($this->user, $this->playlist);

    expect($channel->isCached())->toBeFalse();

    $file = CachedContentFile::factory()->downloading()->forItem($channel)->create();
    expect($channel->isCached())->toBeFalse();

    $file->update(['status' => CachedContentFileStatus::Completed]);
    expect($channel->isCached())->toBeTrue();
});

it('isCached() runs a single query and does not load the playlist relation', function () {
    $channel = makeVodForCacheNow($this->user, $this->playlist)->fresh();

    DB::enableQueryLog();
    $channel->isCached();
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toHaveCount(1)
        ->and($channel->relationLoaded('playlist'))->toBeFalse();
});
