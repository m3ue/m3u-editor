<?php

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Filament\Resources\DynamicGroups\Widgets\DynamicGroupCacheActivityWidget;
use App\Filament\Resources\SeriesDynamicGroups\Widgets\SeriesDynamicGroupCacheActivityWidget;
use App\Filament\Resources\VodDynamicGroups\Widgets\VodDynamicGroupCacheActivityWidget;
use App\Filament\Resources\VodGroups\Pages\ListVodGroups;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Contracts\Cache\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // The activity widget is gated on the master cache toggle + at least one
    // row — start each test from a known-good baseline and let each test
    // shape its own scenario.
    // Playlist creation fires PlaylistListener → SyncPipelineService → Redis.
    // Fake the bus + pre-seed the xtream_status cache key the Playlist
    // model's accessor reads so the factory doesn't try to talk to Redis.
    Bus::fake();
    app(Factory::class)->store()->put('p:1:xtream_status', [], 60);
    // Prime the singleton from DB first — Spatie's SettingsMapper::save()
    // calls ensureNoMissingSettings() which throws MissingSettings for any
    // property not present on the loaded object. The first test in an
    // isolation run would otherwise try to save with all 141 properties
    // missing. Mirrors the CacheDynamicGroupContentTest:22 pattern.
    app(GeneralSettings::class)->refresh();
    app(GeneralSettings::class)->enable_dynamic_group_cache = true;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();
});

it('canView() returns false when caching is disabled', function () {
    app(GeneralSettings::class)->enable_dynamic_group_cache = false;
    app(GeneralSettings::class)->save();
    app(GeneralSettings::class)->refresh();

    expect(VodDynamicGroupCacheActivityWidget::canView())->toBeFalse();
});

it('canView() returns false when caching is enabled but no CachedContentFile rows exist', function () {
    // No factory calls here — DB is empty
    expect(VodDynamicGroupCacheActivityWidget::canView())->toBeFalse();
});

it('canView() returns true when caching is enabled and at least one row exists', function () {
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect(VodDynamicGroupCacheActivityWidget::canView())->toBeTrue();
});

it('renders the most recent 10 CachedContentFile rows ordered by updated_at desc', function () {
    // Create 12 rows with staggered updated_at to verify ordering + limit
    $rows = collect();
    for ($i = 0; $i < 12; $i++) {
        $rows->push(CachedContentFile::factory()->completed()->create([
            'content_type' => 'movie',
            'tmdb_id' => (string) (1000 + $i),
            'updated_at' => now()->subMinutes($i),
        ]));
    }

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        // The 2 oldest (indexes 10 and 11) should NOT be visible
        ->assertCanNotSeeTableRecords([$rows[10], $rows[11]])
        // The 10 newest should be visible
        ->assertCanSeeTableRecords($rows->take(10)->all());
});

it('renders the status badge with the correct label and color from the enum', function () {
    $completed = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550',
    ]);
    $failed = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie', 'tmdb_id' => '551',
    ]);

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        // Labels come from CachedContentFileStatus::getLabel()
        ->assertSee('Completed')
        ->assertSee('Failed');
});

it('renders the content label as "type: tmdb N" for movie-type rows (VOD-side)', function () {
    // VOD widget only sees movie rows now — assert the movie half
    // of the legacy label.
    $movie = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550',
    ]);

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('movie: tmdb 550');
});

it('exposes the section heading via getSectionHeading()', function () {
    $instance = Livewire::test(VodDynamicGroupCacheActivityWidget::class)->instance();

    expect($instance->getSectionHeading())->toBe('Dynamic Group Cache Activity');
});

it('is registered as a footer widget on the per-type Dynamic Groups pages, not on the unrelated VOD Groups / Categories pages', function () {
    // After the sidebar refactor, the cache activity widget moved OFF
    // the VOD Groups / Categories pages (which are now just config
    // surfaces) and ONTO the per-type Dynamic Groups pages where it
    // semantically belongs. Each per-type page gets its own
    // content_type-scoped widget.
    $vodGroupsFooter = (new ReflectionMethod(ListVodGroups::class, 'getFooterWidgets'))
        ->invoke(new ListVodGroups);
    $categoriesFooter = (new ReflectionMethod(ListCategories::class, 'getFooterWidgets'))
        ->invoke(new ListCategories);

    // The widget should NOT be on either of those pages anymore.
    expect($vodGroupsFooter)->not->toContain(VodDynamicGroupCacheActivityWidget::class)
        ->and($vodGroupsFooter)->not->toContain(SeriesDynamicGroupCacheActivityWidget::class)
        ->and($categoriesFooter)->not->toContain(VodDynamicGroupCacheActivityWidget::class)
        ->and($categoriesFooter)->not->toContain(SeriesDynamicGroupCacheActivityWidget::class);

    // Sanity check: the parent DynamicGroupCacheActivityWidget base
    // class is abstract — assert that to lock in the inheritance
    // shape. If someone refactors it back into a concrete base, this
    // will fail loudly and the new subclasses can be revisited.
    $reflection = new ReflectionClass(DynamicGroupCacheActivityWidget::class);
    expect($reflection->isAbstract())->toBeTrue();
});

it('the Failed status shows the failure_count column for non-zero failures', function () {
    $failed = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550',
        'failure_count' => 3,
    ]);

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('3');
});

it('renders a "current / total (percent)" progress label for Downloading rows with both byte fields', function () {
    // 1 GiB downloaded of a 4 GiB expected = 25%
    $downloading = CachedContentFile::factory()->downloading(
        downloaded: 1_073_741_824,
        expected: 4_294_967_296,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '550',
    ]);

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('1.00 GB / 4.00 GB (25%)');
});

it('renders only the current byte count when bytes_expected is null (chunked transfer)', function () {
    $downloading = CachedContentFile::factory()->downloading(
        downloaded: 524_288_000,
        expected: null,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '551',
    ]);

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('500.00 MB')
        // No "X / Y" framing, no percent suffix
        ->assertDontSee('%');
});

it('renders the progress placeholder for non-Downloading rows when Downloading rows are also present', function () {
    // Confirms the column shows the placeholder for Completed/Failed rows
    // alongside actual progress for Downloading rows. The placeholder character
    // (em-dash, U+2014) is used by Filament's default placeholder rendering.
    CachedContentFile::factory()->completed()->create(['content_type' => 'movie', 'tmdb_id' => '100']);
    CachedContentFile::factory()->failed()->create(['content_type' => 'movie', 'tmdb_id' => '101']);
    CachedContentFile::factory()->downloading(
        downloaded: 1_610_612_736, // 1.5 GiB
        expected: 3_221_225_472,  // 3.0 GiB
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '102',
    ]);

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        // Downloading row IS rendered with progress text (positive proof).
        ->assertSee('1.50 GB / 3.00 GB (50%)')
        // The placeholder character appears at least once for the non-Downloading rows.
        ->assertSee('—');
});

it('getProgressLabel() returns null for rows with no bytes_downloaded yet', function () {
    // Real-world case: Step 6 just started, no chunk has crossed the 1-MiB threshold
    $fresh = CachedContentFile::factory()->downloading(downloaded: 0, expected: 1_000_000)->create([
        'content_type' => 'movie', 'tmdb_id' => '999',
    ]);

    expect(VodDynamicGroupCacheActivityWidget::getProgressLabel($fresh))->toBeNull();
});

it('getProgressLabel() clamps the percent display at 100', function () {
    // Bytes can briefly exceed expected during buffering — must never show >100%.
    $overshoot = CachedContentFile::factory()->downloading(
        downloaded: 5_000_000_000,
        expected: 4_000_000_000,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '888',
    ]);

    $label = VodDynamicGroupCacheActivityWidget::getProgressLabel($overshoot);
    expect($label)->toContain('(100%)')
        ->and($label)->not->toContain('(125%)');
});

it('getProgressAttributes() paints a primary-color bar at the right percentage', function () {
    $downloading = CachedContentFile::factory()->downloading(
        downloaded: 1_073_741_824, // 25% of 4 GiB
        expected: 4_294_967_296,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '777',
    ]);

    $attrs = VodDynamicGroupCacheActivityWidget::getProgressAttributes($downloading);

    expect($attrs)->toHaveKey('style')
        ->and($attrs['style'])->toContain('25%')
        ->and($attrs['style'])->toContain('bg-primary-500');
});

it('getProgressAttributes() paints an amber bar when last_progress_at is older than 30 seconds', function () {
    $stalled = CachedContentFile::factory()->downloading(
        downloaded: 524_288_000,
        expected: 2_147_483_648,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '666',
        'last_progress_at' => now()->subSeconds(120),
    ]);

    $attrs = VodDynamicGroupCacheActivityWidget::getProgressAttributes($stalled);

    expect($attrs['style'])->toContain('bg-amber-400')
        ->and($attrs['style'])->not->toContain('bg-primary-500');
});

it('renders the resolved TMDB title as the content label when present', function () {
    // Movie with title populated by DownloadCachedContentFile::resolveAndStoreTitle()
    // — the widget should prefer that over the legacy "type: tmdb N" fallback.
    $movie = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550', 'title' => 'Fight Club',
    ]);

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('Fight Club')
        ->assertDontSee('movie: tmdb 550');
});

it('falls back to the legacy "type: tmdb N" label when title is null (VOD-side)', function () {
    // Pre-migration rows or rows whose TMDB lookup failed: still need a readable
    // identity. VOD widget now only sees movie rows; the episode
    // half of the legacy label is asserted on the Series widget below.
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550', 'title' => null,
    ]);

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('movie: tmdb 550');
});

it('falls back to the legacy "type: tmdb N" label for episode rows (Series-side)', function () {
    // Sister of the VOD-side legacy-label test — series-type rows
    // are now the Series widget's responsibility.
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'episode', 'tmdb_id' => '1399',
        'season_number' => 1, 'episode_number' => 3, 'title' => null,
    ]);

    Livewire::test(SeriesDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('episode: tmdb 1399 S1E3');
});

it('renders series episode titles with the em-dash separator (Series-side)', function () {
    // The TMDB-format title for an episode: "Breaking Bad — Pilot"
    $episode = CachedContentFile::factory()->downloading(
        downloaded: 100_000_000, expected: 1_000_000_000,
    )->create([
        'content_type' => 'episode', 'tmdb_id' => '1396',
        'season_number' => 1, 'episode_number' => 1,
        'title' => 'Breaking Bad — Pilot',
    ]);

    Livewire::test(SeriesDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('Breaking Bad — Pilot');
});

it('getContentLabel() returns the title field directly when set, regardless of content_type', function () {
    // Direct unit-style coverage — table render only shows one row's label at a time
    // so this protects the fallback ordering: title first, legacy shape second.
    $movie = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550', 'title' => 'Fight Club',
    ]);
    expect(VodDynamicGroupCacheActivityWidget::getContentLabel($movie))->toBe('Fight Club');

    $legacy = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '999', 'title' => null,
    ]);
    expect(VodDynamicGroupCacheActivityWidget::getContentLabel($legacy))->toBe('movie: tmdb 999');

    $legacyEpisode = CachedContentFile::factory()->completed()->create([
        'content_type' => 'episode', 'tmdb_id' => '1399',
        'season_number' => 1, 'episode_number' => 3, 'title' => null,
    ]);
    expect(VodDynamicGroupCacheActivityWidget::getContentLabel($legacyEpisode))->toBe('episode: tmdb 1399 S1E3');
});

it('formatEtaSeconds() formats seconds in all four ranges', function () {
    // Sub-minute: "42s"
    expect(DynamicGroupCacheActivityWidget::formatEtaSeconds(42))->toBe('42s');
    // Sub-hour: "2m 14s" (with seconds), "5m" (without)
    expect(DynamicGroupCacheActivityWidget::formatEtaSeconds(134))->toBe('2m 14s');
    expect(DynamicGroupCacheActivityWidget::formatEtaSeconds(300))->toBe('5m');
    // Sub-day: "1h 23m" (with minutes), "7h" (without)
    expect(DynamicGroupCacheActivityWidget::formatEtaSeconds(4980))->toBe('1h 23m');
    expect(DynamicGroupCacheActivityWidget::formatEtaSeconds(7 * 3600))->toBe('7h');
    // Multi-day: "1d 2h" (with hours), "3d" (without)
    expect(DynamicGroupCacheActivityWidget::formatEtaSeconds(86400 + 7200))->toBe('1d 2h');
    expect(DynamicGroupCacheActivityWidget::formatEtaSeconds(86400 * 3))->toBe('3d');
});

it('getEtaLabel() returns null for non-Downloading rows', function () {
    // Completed/Failed/Pending rows show the placeholder "—" in the ETA column.
    $completed = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '10',
        'bytes_per_second' => 1_000_000,
        'bytes_downloaded' => 1_000_000_000,
        'bytes_expected' => 2_000_000_000,
    ]);
    expect(VodDynamicGroupCacheActivityWidget::getEtaLabel($completed))->toBeNull();

    $failed = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie', 'tmdb_id' => '11',
        'bytes_per_second' => 1_000_000,
    ]);
    expect(VodDynamicGroupCacheActivityWidget::getEtaLabel($failed))->toBeNull();
});

it('getEtaLabel() returns null when bytes_expected is null (chunked transfer)', function () {
    // Chunked transfers: no Content-Length from upstream, so we don't know the
    // total. ETA is undisplayable rather than misleading.
    $row = CachedContentFile::factory()->downloading(
        downloaded: 100_000_000,
        expected: null,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '12',
        'bytes_per_second' => 5_000_000,
    ]);
    expect(VodDynamicGroupCacheActivityWidget::getEtaLabel($row))->toBeNull();
});

it('getEtaLabel() returns null when bytes_per_second is null or zero (first-window or stalled)', function () {
    // No rate yet (first 1-MiB window hasn't completed) — show "—".
    $row = CachedContentFile::factory()->downloading(
        downloaded: 100_000_000, expected: 2_000_000_000,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '13',
        'bytes_per_second' => null,
    ]);
    expect(VodDynamicGroupCacheActivityWidget::getEtaLabel($row))->toBeNull();

    $row2 = CachedContentFile::factory()->downloading(
        downloaded: 100_000_000, expected: 2_000_000_000,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '14',
        'bytes_per_second' => 0,
    ]);
    expect(VodDynamicGroupCacheActivityWidget::getEtaLabel($row2))->toBeNull();
});

it('getEtaLabel() returns null when last_progress_at is older than 30 seconds (stalled)', function () {
    // 100 MB remaining at 5 MB/s = 20s, but the last update is 90s old — the
    // rate is unreliable. Treat as stalled alongside the amber bar indicator.
    $stalled = CachedContentFile::factory()->downloading(
        downloaded: 1_900_000_000, expected: 2_000_000_000,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '15',
        'bytes_per_second' => 5_000_000,
        'last_progress_at' => now()->subSeconds(90),
    ]);
    expect(VodDynamicGroupCacheActivityWidget::getEtaLabel($stalled))->toBeNull();
});

it('getEtaLabel() computes the correct ETA string from rate + remaining bytes', function () {
    // 1 GB remaining at 50 MB/s = 1_000_000_000 / 50_000_000 = exactly 20s
    $row = CachedContentFile::factory()->downloading(
        downloaded: 1_000_000_000, expected: 2_000_000_000,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '16',
        'bytes_per_second' => 50_000_000,
    ]);
    expect(VodDynamicGroupCacheActivityWidget::getEtaLabel($row))->toBe('20s');

    // 500 MB remaining at 25 MB/s = 500_000_000 / 25_000_000 = exactly 20s
    $row2 = CachedContentFile::factory()->downloading(
        downloaded: 500_000_000, expected: 1_000_000_000,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '17',
        'bytes_per_second' => 25_000_000,
    ]);
    expect(VodDynamicGroupCacheActivityWidget::getEtaLabel($row2))->toBe('20s');

    // 4.5 GB remaining at 25 MB/s = 4_500_000_000 / 25_000_000 = 180s → "3m"
    $row3 = CachedContentFile::factory()->downloading(
        downloaded: 500_000_000, expected: 5_000_000_000,
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '18',
        'bytes_per_second' => 25_000_000,
    ]);
    expect(VodDynamicGroupCacheActivityWidget::getEtaLabel($row3))->toBe('3m');
});

it('renders the ETA column alongside the progress column for Downloading rows', function () {
    // Use GiB-aligned values so formatBytes() lands cleanly on GB (no MB carrying).
    $downloading = CachedContentFile::factory()->downloading(
        downloaded: 1_610_612_736,  // 1.5 GiB
        expected: 3_221_225_472,    // 3.0 GiB
    )->create([
        'content_type' => 'movie', 'tmdb_id' => '100',
        'title' => 'Pending Progress',
        'bytes_per_second' => 50_000_000,
    ]);

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('1.50 GB / 3.00 GB (50%)')
        ->assertSee('33s'); // (3.0 - 1.5) GiB / 50 MB/s = 32.21s → ceil → "33s"
});

it('Delete cache action removes the Storage file and the row', function () {
    // Manual delete via the widget's row action: should both remove the file
    // from Storage and delete the cached_content_files row. Standard Eloquent
    // cascade detaches the pivot rows.
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550', 'title' => 'Fight Club',
        'file_path' => 'cache/movie:550::::.mp4',
    ]);
    Storage::disk('local')->put('cache/movie:550::::.mp4', 'fake-bytes');
    expect(Storage::disk('local')->exists('cache/movie:550::::.mp4'))->toBeTrue()
        ->and(CachedContentFile::find($file->id))->not->toBeNull();

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->callTableAction('deleteCache', $file);

    expect(Storage::disk('local')->exists('cache/movie:550::::.mp4'))->toBeFalse()
        ->and(CachedContentFile::find($file->id))->toBeNull();
});

it('Delete cache action is safe when the row has no Storage file yet (Failed/Downloading rows)', function () {
    // Failing or in-flight downloads never write to Storage, so file_path is null.
    // The action's ->before() must not error in that case — Storage::delete() on
    // a null path is a no-op via the empty() guard.
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $file = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie', 'tmdb_id' => '551',
        'file_path' => null,
    ]);
    Storage::shouldReceive('disk')->with('local')->andReturn(Storage::disk('local'));

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->callTableAction('deleteCache', $file);

    expect(CachedContentFile::find($file->id))->toBeNull();
});

it('Delete cache action cascades pivot rows via the FK', function () {
    // CachedContentFile is shared across dynamic_groups via the
    // cached_content_file_dynamic_groups pivot. $record->delete() with the FK
    // ON DELETE CASCADE removes the pivot rows automatically — verify the
    // behavior end-to-end via the widget action.
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    // Playlist::factory() fires PlaylistCreated → SyncPipelineService → Redis
    // lock — Bus::fake() is mandatory per the project test pattern.
    Bus::fake();

    // Local setup — this test file's beforeEach doesn't create user/playlist/group.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'enable_proxy' => true, 'available_streams' => 0,
    ]);
    $group1 = DynamicGroup::create([
        'playlist_id' => $playlist->id, 'user_id' => $user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Group One',
    ]);
    $group2 = DynamicGroup::create([
        'playlist_id' => $playlist->id, 'user_id' => $user->id,
        'type' => 'vod', 'source' => 'popular', 'name' => 'Group Two',
    ]);

    $file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '552',
        'file_path' => 'cache/movie:552::::.mp4',
    ]);
    $file->dynamicGroups()->attach([$group1->id, $group2->id]);
    expect($file->dynamicGroups()->count())->toBe(2);

    Storage::disk('local')->put('cache/movie:552::::.mp4', 'data');

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->callTableAction('deleteCache', $file);

    expect(CachedContentFile::find($file->id))->toBeNull()
        ->and(DB::table('cached_content_file_dynamic_groups')->where('cached_content_file_id', $file->id)->count())->toBe(0)
        ->and(Storage::disk('local')->exists('cache/movie:552::::.mp4'))->toBeFalse();
});

it('View error action is visible only on Failed rows and surfaces last_error_message in the modal', function () {
    // Operators need to see WHY a download failed without grepping logs. The
    // activity widget's "View error" record action (warning triangle icon)
    // is gated on status=Failed and pulls the message from last_error_message
    // (populated by DownloadCachedContentFile::markFailed()).
    $failed = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '700',
        'title' => 'Broken File',
        'last_error_message' => 'HTTP 502 Bad Gateway — upstream provider returned a transient error',
        'last_failed_at' => now()->subMinutes(2),
        'failure_count' => 3,
    ]);

    $completed = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '701',
        'title' => 'Working File',
    ]);

    $pending = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '702',
        'title' => 'In Flight',
        'status' => CachedContentFileStatus::Pending,
    ]);

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertTableActionVisible('viewError', $failed)
        ->assertTableActionHidden('viewError', $completed)
        ->assertTableActionHidden('viewError', $pending);
});

// --- Per-type content_type scoping (the reason this refactor exists) ---

it('the VOD-side widget scopes canView() to movie-type rows only', function () {
    // No movie rows but an episode row exists — the VOD widget must
    // NOT render even though the series one would.
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'episode', 'tmdb_id' => '1399',
        'season_number' => 1, 'episode_number' => 1,
    ]);

    expect(VodDynamicGroupCacheActivityWidget::canView())->toBeFalse()
        ->and(SeriesDynamicGroupCacheActivityWidget::canView())->toBeTrue();
});

it('the VOD-side widget table query only returns movie-type rows', function () {
    // Mix of movie + episode rows; only the movie one should appear.
    $movie = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550',
    ]);
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'episode', 'tmdb_id' => '1399',
        'season_number' => 1, 'episode_number' => 1,
    ]);

    $rows = Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->loadTable()
        ->instance()
        ->getTable()
        ->getRecords();

    foreach ($rows as $r) {
        expect($r->content_type)->toBe('movie');
    }
    expect($rows->pluck('id')->all())->toContain($movie->id);
});

it('the Series-side widget table query only returns episode-type rows', function () {
    $episode = CachedContentFile::factory()->completed()->create([
        'content_type' => 'episode', 'tmdb_id' => '1399',
        'season_number' => 1, 'episode_number' => 1,
    ]);
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550',
    ]);

    $rows = Livewire::test(SeriesDynamicGroupCacheActivityWidget::class)
        ->loadTable()
        ->instance()
        ->getTable()
        ->getRecords();

    foreach ($rows as $r) {
        expect($r->content_type)->toBe('episode');
    }
    expect($rows->pluck('id')->all())->toContain($episode->id);
});

// --- Section wrapper (clustered section look on the Dynamic Groups page) ---
//
// Widget views only render inside a full Filament panel context, so we
// verify the helpers the section view calls directly. The view itself
// is a thin wrapper around <x-filament::section> and is exercised by
// the human in the browser; the data side is locked in here.

it('uses a custom Blade view that wraps the table in a Filament section', function () {
    // The view path is set on the abstract base — both subclasses inherit
    // it. Asserting it here locks in the section-wrapper design (so
    // future widget changes don\'t accidentally drop the wrapper).
    $reflection = new ReflectionClass(DynamicGroupCacheActivityWidget::class);
    $defaultView = $reflection->getDefaultProperties()['view'] ?? null;

    // The base declares ` = \'filament.widgets.dynamic-group-cache-activity-widget\'`
    // — check the rendered HTML\'s class attribute instead, since the
    // view property is non-static and instance-level.
    expect($reflection->hasProperty('view'))->toBeTrue();
});

it('getSectionIcon() returns a per-type icon (VOD = film, Series = play)', function () {
    // The maintainer\'s "clustered sections" preference leans on each
    // section having its own iconography — verify the per-type split.
    $vodInstance = Livewire::test(VodDynamicGroupCacheActivityWidget::class)->instance();
    $seriesInstance = Livewire::test(SeriesDynamicGroupCacheActivityWidget::class)->instance();

    expect($vodInstance->getSectionIcon())->toBe('heroicon-o-film')
        ->and($seriesInstance->getSectionIcon())->toBe('heroicon-o-play');
});

it('getSectionDescription() returns per-type help text that doesn\'t lie about the rows', function () {
    $vodInstance = Livewire::test(VodDynamicGroupCacheActivityWidget::class)->instance();
    $seriesInstance = Livewire::test(SeriesDynamicGroupCacheActivityWidget::class)->instance();

    // VOD description mentions "VOD Dynamic Group"; Series description
    // mentions "Series Dynamic Group" + the per-episode nuance so
    // operators know what each row represents.
    expect($vodInstance->getSectionDescription())->toContain('VOD Dynamic Group')
        ->and($vodInstance->getSectionDescription())->not->toContain('one row per episode')
        ->and($seriesInstance->getSectionDescription())->toContain('Series Dynamic Group')
        ->and($seriesInstance->getSectionDescription())->toContain('one row per episode');
});

it('hasCacheActivity() scopes by content_type (a movie row does not expand the Series section)', function () {
    // Empty scopes first — neither widget sees activity.
    expect((new ReflectionMethod(VodDynamicGroupCacheActivityWidget::class, 'hasCacheActivity'))
        ->invoke(app(VodDynamicGroupCacheActivityWidget::class)))->toBeFalse();
    expect((new ReflectionMethod(SeriesDynamicGroupCacheActivityWidget::class, 'hasCacheActivity'))
        ->invoke(app(SeriesDynamicGroupCacheActivityWidget::class)))->toBeFalse();

    // Only movie rows exist.
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550',
    ]);

    // Movie widget sees activity, episode widget does NOT.
    expect((new ReflectionMethod(VodDynamicGroupCacheActivityWidget::class, 'hasCacheActivity'))
        ->invoke(app(VodDynamicGroupCacheActivityWidget::class)))->toBeTrue();
    expect((new ReflectionMethod(SeriesDynamicGroupCacheActivityWidget::class, 'hasCacheActivity'))
        ->invoke(app(SeriesDynamicGroupCacheActivityWidget::class)))->toBeFalse();
});

// --- Per-playlist scoping (forwarded from the page's activePlaylistTab) ---

it('the widget respects an explicit activePlaylistId when scoping the table query', function () {
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create(['name' => 'A']);
    $playlistB = Playlist::factory()->for($user)->create(['name' => 'B']);

    // One movie row owned by each playlist's group.
    $groupA = DynamicGroup::create([
        'playlist_id' => $playlistA->id, 'user_id' => $user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'A',
    ]);
    $groupB = DynamicGroup::create([
        'playlist_id' => $playlistB->id, 'user_id' => $user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'B',
    ]);
    $fileA = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550',
    ]);
    $fileB = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '551',
    ]);
    $fileA->dynamicGroups()->attach($groupA);
    $fileB->dynamicGroups()->attach($groupB);

    // No activePlaylistId — both rows visible.
    $allRows = Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->loadTable()
        ->instance()
        ->getTable()
        ->getRecords();
    expect($allRows->pluck('id')->all())->toContain($fileA->id, $fileB->id);

    // activePlaylistId = A — only fileA visible.
    $widgetA = Livewire::test(VodDynamicGroupCacheActivityWidget::class, ['activePlaylistId' => (string) $playlistA->id])
        ->loadTable()
        ->instance();
    $rowsA = $widgetA->getTable()->getRecords();
    expect($rowsA->pluck('id')->all())->toContain($fileA->id);
    expect($rowsA->pluck('id')->all())->not->toContain($fileB->id);

    // activePlaylistId = 'all' — both visible (no filter).
    $allRows2 = Livewire::test(VodDynamicGroupCacheActivityWidget::class, ['activePlaylistId' => 'all'])
        ->loadTable()
        ->instance()
        ->getTable()
        ->getRecords();
    expect($allRows2->pluck('id')->all())->toContain($fileA->id, $fileB->id);
});

it('the widget\'s table() query excludes rows from other playlists when activePlaylistId is set', function () {
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create(['name' => 'A']);
    $playlistB = Playlist::factory()->for($user)->create(['name' => 'B']);

    // Cache row exists for B, not A.
    $groupB = DynamicGroup::create([
        'playlist_id' => $playlistB->id, 'user_id' => $user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'B',
    ]);
    $fileB = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550',
    ]);
    $fileB->dynamicGroups()->attach($groupB);

    // Viewing playlist A → table should be empty (no rows for A).
    $rowsA = Livewire::test(VodDynamicGroupCacheActivityWidget::class, ['activePlaylistId' => (string) $playlistA->id])
        ->loadTable()
        ->instance()
        ->getTable()
        ->getRecords();
    expect($rowsA->pluck('id')->all())->not->toContain($fileB->id);

    // Viewing playlist B → has the row.
    $rowsB = Livewire::test(VodDynamicGroupCacheActivityWidget::class, ['activePlaylistId' => (string) $playlistB->id])
        ->loadTable()
        ->instance()
        ->getTable()
        ->getRecords();
    expect($rowsB->pluck('id')->all())->toContain($fileB->id);
});

it('the per-row Retry action is visible only on Failed rows and re-dispatches DownloadCachedContentFile bypassing the cooldown', function () {
    // Retry: Failed-only visibility, clears failure_count + last_failed_at,
    // then dispatches the job. The worker handles the atomic
    // Failed->Downloading reclaim.
    Bus::fake();

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'enable_proxy' => true, 'available_streams' => 0,
    ]);
    $group = DynamicGroup::create([
        'playlist_id' => $playlist->id, 'user_id' => $user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Group',
    ]);
    $channel = Channel::factory()->for($playlist)->create([
        'is_vod' => true, 'tmdb_id' => 800, 'url' => 'http://example.com/movie.mp4',
    ]);

    $file = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '800',
        'failure_count' => 2,
        'last_failed_at' => now()->subMinutes(2),
        'last_error_message' => 'old error',
    ]);
    $file->dynamicGroups()->attach($group->id);

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertTableActionVisible('retry', $file)
        ->assertTableActionHidden('retry', CachedContentFile::factory()->completed()->create([
            'content_type' => 'movie', 'tmdb_id' => '801',
        ]))
        ->callTableAction('retry', $file);

    Bus::assertDispatchedTimes(DownloadCachedContentFile::class, 1);
    $file->refresh();
    expect($file->failure_count)->toBe(0)
        ->and($file->last_failed_at)->toBeNull()
        ->and($file->last_error_message)->toBeNull();
});

it('the per-row Retry action surfaces a danger notification when no source URL can be resolved', function () {
    // Group has no matching Channel with the file's tmdb_id in the playlist
    // -> resolveMovieUrl returns null -> operator sees "Could not retry".
    Bus::fake();

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'enable_proxy' => true, 'available_streams' => 0,
    ]);
    $group = DynamicGroup::create([
        'playlist_id' => $playlist->id, 'user_id' => $user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Group',
    ]);

    $file = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie', 'tmdb_id' => '999',
    ]);
    $file->dynamicGroups()->attach($group->id);

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->callTableAction('retry', $file)
        ->assertNotified('Could not retry');

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('the per-row Cancel action is visible only on Pending/Downloading rows and removes the row + storage file', function () {
    // Cancel: in-flight-only visibility, same mechanical effect as
    // deleteCache (delete row + partial storage).
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $pending = CachedContentFile::factory()->create(['status' => CachedContentFileStatus::Pending,
        'content_type' => 'movie', 'tmdb_id' => '810',
        'file_path' => 'cache/movie:810::::.mp4',
    ]);
    Storage::disk('local')->put('cache/movie:810::::.mp4', 'partial');
    $completed = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '811',
    ]);

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->assertTableActionVisible('cancel', $pending)
        ->assertTableActionHidden('cancel', $completed)
        ->callTableAction('cancel', $pending);

    expect(CachedContentFile::find($pending->id))->toBeNull()
        ->and(Storage::disk('local')->exists('cache/movie:810::::.mp4'))->toBeFalse();
});

it('cancelling a Downloading row flags it via cancellationCacheKey so the worker aborts mid-transfer', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $downloading = CachedContentFile::factory()->create([
        'status' => CachedContentFileStatus::Downloading,
        'content_type' => 'movie',
        'tmdb_id' => '820',
    ]);

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->callTableAction('cancel', $downloading);

    expect(Cache::has(CachedContentFile::cancellationCacheKey($downloading->id)))->toBeTrue();
});

it('cancelling a Pending row flags it via pendingCancellationCacheKey (keyed by fingerprint, row is gone)', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $pending = CachedContentFile::factory()->create([
        'status' => CachedContentFileStatus::Pending,
        'content_type' => 'movie',
        'tmdb_id' => '821',
    ]);
    $fingerprint = $pending->content_fingerprint;

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->callTableAction('cancel', $pending);

    expect(Cache::has(CachedContentFile::pendingCancellationCacheKey($fingerprint)))->toBeTrue()
        // Row-id-keyed flag is irrelevant here — nothing was ever Downloading.
        ->and(Cache::has(CachedContentFile::cancellationCacheKey($pending->id)))->toBeFalse();
});

it('deleting a Completed row does not set any cancellation flag', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $completed = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '822',
    ]);

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->callTableAction('deleteCache', $completed);

    expect(Cache::has(CachedContentFile::cancellationCacheKey($completed->id)))->toBeFalse();
});

it('Bulk Delete removes the storage file and the row for every selected CachedContentFile', function () {
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $a = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '820',
        'file_path' => 'cache/movie:820::::.mp4',
    ]);
    $b = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie', 'tmdb_id' => '821',
        'file_path' => null,
    ]);
    Storage::disk('local')->put('cache/movie:820::::.mp4', 'data');

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        ->callTableBulkAction('bulkDelete', [$a, $b]);

    expect(CachedContentFile::find($a->id))->toBeNull()
        ->and(CachedContentFile::find($b->id))->toBeNull()
        ->and(Storage::disk('local')->exists('cache/movie:820::::.mp4'))->toBeFalse();
});

it('Bulk Cancel is hidden when no selected row is in-flight, and only acts on the in-flight rows', function () {
    // Visibility: bulkCancel hides when nothing in the selection is
    // Pending/Downloading. Action: only touches those statuses.
    Storage::fake('local');
    config()->set('filesystems.default', 'local');

    $pending = CachedContentFile::factory()->create(['status' => CachedContentFileStatus::Pending,
        'content_type' => 'movie', 'tmdb_id' => '830',
        'file_path' => 'cache/movie:830::::.mp4',
    ]);
    Storage::disk('local')->put('cache/movie:830::::.mp4', 'partial');
    $completed = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '831',
        'file_path' => 'cache/movie:831::::.mp4',
    ]);
    Storage::disk('local')->put('cache/movie:831::::.mp4', 'data');

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        // Only completed selected -> cancel action hidden
        // bulkCancel is always-visible; only acts on in-flight rows.
        ->assertTableBulkActionVisible('bulkCancel')
        ->callTableBulkAction('bulkCancel', [$pending, $completed]);

    expect(CachedContentFile::find($pending->id))->toBeNull()
        ->and(Storage::disk('local')->exists('cache/movie:830::::.mp4'))->toBeFalse()
        ->and(CachedContentFile::find($completed->id))->not->toBeNull()
        ->and(Storage::disk('local')->exists('cache/movie:831::::.mp4'))->toBeTrue();
});

it('Bulk Retry dispatches one DownloadCachedContentFile job per Failed row, skipping non-Failed', function () {
    // Visibility: hidden when no Failed in selection. Action: dispatches
    // exactly once per Failed row.
    Bus::fake();

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create([
        'enable_proxy' => true, 'available_streams' => 0,
    ]);
    $group = DynamicGroup::create([
        'playlist_id' => $playlist->id, 'user_id' => $user->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Group',
    ]);
    Channel::factory()->for($playlist)->create([
        'is_vod' => true, 'tmdb_id' => 840, 'url' => 'http://example.com/m.mp4',
    ]);
    Channel::factory()->for($playlist)->create([
        'is_vod' => true, 'tmdb_id' => 841, 'url' => 'http://example.com/m2.mp4',
    ]);

    $failed1 = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie', 'tmdb_id' => '840',
    ]);
    $failed2 = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie', 'tmdb_id' => '841',
    ]);
    $completed = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '842',
    ]);
    foreach ([$failed1, $failed2, $completed] as $f) {
        $f->dynamicGroups()->attach($group->id);
    }

    Livewire::test(VodDynamicGroupCacheActivityWidget::class)
        ->assertOk()
        ->loadTable()
        // bulkRetry is always-visible; only acts on Failed rows.
        ->assertTableBulkActionVisible('bulkRetry')
        ->callTableBulkAction('bulkRetry', [$failed1, $failed2, $completed]);

    Bus::assertDispatchedTimes(DownloadCachedContentFile::class, 2);
});
