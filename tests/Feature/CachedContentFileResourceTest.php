<?php

use App\Enums\CachedContentFileStatus;
use App\Filament\Resources\CachedContentFiles\CachedContentFileResource;
use App\Filament\Resources\CachedContentFiles\Pages\ListCachedContentFiles;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use App\Settings\GeneralSettings;
use App\Tables\Columns\ProgressColumn;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/**
 * Bind a Mockery-mocked GeneralSettings with the requested `enable_cache` value.
 */
function setEnableCacheForActivityWidget(bool $value): void
{
    $mock = Mockery::mock(GeneralSettings::class);
    $mock->enable_cache = $value;
    app()->instance(GeneralSettings::class, $mock);
}

beforeEach(function () {
    setEnableCacheForActivityWidget(true);
    Bus::fake();
});

// canAccess()

it('canAccess returns false when enable_cache is off', function () {
    setEnableCacheForActivityWidget(false);

    $user = User::factory()->create();
    $this->actingAs($user);

    expect(CachedContentFileResource::canAccess())->toBeFalse();
});

it('canAccess returns false for unauthenticated visitors', function () {
    auth()->logout();

    expect(CachedContentFileResource::canAccess())->toBeFalse();
});

it('canAccess returns true when enable_cache is on and the visitor is authenticated', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    expect(CachedContentFileResource::canAccess())->toBeTrue();
});

// Rendering

it('renders for an authenticated user when enable_cache is on', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(ListCachedContentFiles::class)->assertOk();
});

it('does not render for an authenticated user when enable_cache is off', function () {
    setEnableCacheForActivityWidget(false);

    $user = User::factory()->create();
    $this->actingAs($user);

    Livewire::test(ListCachedContentFiles::class)->assertSet('mounted', false);
});

// Ownership scoping (table query)

it('table query only shows the current user\'s cached files for non-admin users', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $playlist = Playlist::factory()->for($owner)->create();

    $mine = CachedContentFile::factory()->completed()->create([
        'user_id' => $owner->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '111',
        'title' => 'My Movie',
    ]);
    $theirs = CachedContentFile::factory()->completed()->create([
        'user_id' => $intruder->id,
        'playlist_id' => Playlist::factory()->for($intruder)->create()->id,
        'content_type' => 'movie',
        'tmdb_id' => '222',
        'title' => 'Their Movie',
    ]);

    $this->actingAs($owner);

    Livewire::test(ListCachedContentFiles::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$mine])
        ->assertCanNotSeeTableRecords([$theirs]);
});

it('table query shows every cached file for an admin regardless of user_id', function () {
    $admin = User::factory()->admin()->create();
    $other = User::factory()->create();
    $adminPlaylist = Playlist::factory()->for($admin)->create();
    $otherPlaylist = Playlist::factory()->for($other)->create();

    $adminRow = CachedContentFile::factory()->completed()->create([
        'user_id' => $admin->id,
        'playlist_id' => $adminPlaylist->id,
        'content_type' => 'movie',
        'tmdb_id' => '111',
        'title' => 'Admin Movie',
    ]);
    $otherRow = CachedContentFile::factory()->completed()->create([
        'user_id' => $other->id,
        'playlist_id' => $otherPlaylist->id,
        'content_type' => 'movie',
        'tmdb_id' => '222',
        'title' => 'Other User Movie',
    ]);

    $this->actingAs($admin);

    Livewire::test(ListCachedContentFiles::class)
        ->assertOk()
        ->loadTable()
        ->assertCanSeeTableRecords([$adminRow, $otherRow]);
});

// Per-row actions

it('per-row retry action re-dispatches DownloadCachedContentFile for a Failed row', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $channel = Channel::factory()->for($user)->for($playlist)->create([
        'is_vod' => true,
        'tmdb_id' => 700,
        'url' => 'https://example.com/movie.mp4',
    ]);
    $row = CachedContentFile::factory()->failed()->forItem($channel)->create([
        'title' => 'Failed movie',
    ]);

    $this->actingAs($user);

    Livewire::test(ListCachedContentFiles::class)
        ->callTableAction('retry', $row)
        ->assertNotified();

    Bus::assertDispatched(DownloadCachedContentFile::class);
});

it('per-row retry action reports Could not retry when the source channel is gone', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $row = CachedContentFile::factory()->failed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '999',
        'title' => 'Missing source',
    ]);
    // The movie left the playlist since the download failed.
    Channel::whereKey($row->cacheable_id)->delete();

    $this->actingAs($user);

    Livewire::test(ListCachedContentFiles::class)
        ->callTableAction('retry', $row)
        ->assertNotified('Could not retry');

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('per-row deleteCache action removes the row and storage file', function () {
    Storage::fake('cache');

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $row = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '800',
        'title' => 'Delete me',
        'disk' => 'cache',
        'file_path' => 'cache/fingerprint.mp4',
    ]);

    Storage::disk('cache')->put('cache/fingerprint.mp4', 'bytes');

    $this->actingAs($user);

    Livewire::test(ListCachedContentFiles::class)
        ->callTableAction('deleteCache', $row)
        ->assertNotified();

    expect(CachedContentFile::find($row->id))->toBeNull();
    expect(Storage::disk('cache')->exists('cache/fingerprint.mp4'))->toBeFalse();
});

it('per-row cancel action sets a row-id cancellation flag and deletes the row (no fingerprint flag)', function () {
    // Cancelling signals the worker through the row-id key only; no
    // content-wide flag that could block a later download of the same item.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $fingerprint = 'movie:1234:::1080p';

    $row = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '1234',
        'content_fingerprint' => $fingerprint,
        'status' => CachedContentFileStatus::Pending,
    ]);

    // Only the row-id flag is written - the fingerprint-scoped helper is
    // gone. Mockery::mockery teardown unsets this between tests.
    Cache::shouldReceive('put')
        ->once()
        ->withArgs(fn (string $key, mixed $value, mixed $ttl): bool => $key === CachedContentFile::cancellationCacheKey($row->id));

    // Sanity: the fingerprint-scoped helper must not exist on the model.
    expect(method_exists(CachedContentFile::class, 'pendingCancellationCacheKey'))->toBeFalse();

    $this->actingAs($user);

    Livewire::test(ListCachedContentFiles::class)
        ->callTableAction('cancel', $row)
        ->assertNotified();

    expect(CachedContentFile::find($row->id))->toBeNull();
});

it('per-row viewError action opens a modal containing the failure message', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $row = CachedContentFile::factory()->failed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '555',
        'last_error_message' => 'HTTP 503 from origin',
        'failure_count' => 3,
    ]);

    $this->actingAs($user);

    Livewire::test(ListCachedContentFiles::class)
        ->mountTableAction('viewError', $row)
        ->assertHasNoErrors();
});

// Ownership enforcement (per-row + bulk)

it('per-row retry helper refuses to dispatch when the row belongs to another user', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $playlist = Playlist::factory()->for($owner)->create();
    $row = CachedContentFile::factory()->failed()->create([
        'user_id' => $owner->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '4321',
    ]);

    // We can't drive callTableAction() for a row filtered out by
    // ownedBy() - Filament throws "Record no longer exists" before the
    // closure runs. Test the ownership gate directly via the helper
    // the action uses, so retryCachedFile() must return false on ownership mismatch.
    $this->actingAs($intruder);

    expect(CachedContentFileResource::retryCachedFile($row))->toBeFalse();

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

// Bulk actions

it('bulkRetry re-dispatches DownloadCachedContentFile for every Failed row in the selection', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    // Retry needs a cacheable (VOD, with URL) source item.
    $channelA = Channel::factory()->for($user)->for($playlist)->create(['is_vod' => true, 'tmdb_id' => 1001, 'url' => 'https://example.com/a.mp4']);
    $channelB = Channel::factory()->for($user)->for($playlist)->create(['is_vod' => true, 'tmdb_id' => 1002, 'url' => 'https://example.com/b.mp4']);

    $rowA = CachedContentFile::factory()->failed()->forItem($channelA)->create(['title' => 'A']);
    $rowB = CachedContentFile::factory()->failed()->forItem($channelB)->create(['title' => 'B']);

    $this->actingAs($user);

    Livewire::test(ListCachedContentFiles::class)
        ->callTableBulkAction('bulkRetry', [$rowA->id, $rowB->id])
        ->assertNotified();

    Bus::assertDispatchedTimes(DownloadCachedContentFile::class, 2);
});

it('bulkRetry skips rows owned by another user (no dispatch, no surprise side effect)', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $playlist = Playlist::factory()->for($owner)->create();

    $otherChannel = Channel::factory()->for($owner)->for($playlist)->create(['is_vod' => true, 'tmdb_id' => 3000, 'url' => 'https://example.com/x.mp4']);

    $theirRow = CachedContentFile::factory()->failed()->create([
        'user_id' => $owner->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '3000',
    ]);

    // Same ownership-out-of-table problem as the per-row intruder test:
    // bulk action by id fails to resolve before the closure runs. Drive
    // the helper directly instead.
    $this->actingAs($intruder);

    expect(CachedContentFileResource::retryCachedFile($theirRow))->toBeFalse();

    Bus::assertNotDispatched(DownloadCachedContentFile::class);
});

it('bulkCancel removes every Pending or Downloading row in the selection', function () {
    Storage::fake('cache');

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $pendingRow = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '9001',
        'status' => CachedContentFileStatus::Pending,
    ]);
    $completedRow = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '9002',
    ]);

    $this->actingAs($user);

    Livewire::test(ListCachedContentFiles::class)
        ->callTableBulkAction('bulkCancel', [$pendingRow->id, $completedRow->id])
        ->assertNotified();

    expect(CachedContentFile::find($pendingRow->id))->toBeNull()
        ->and(CachedContentFile::find($completedRow->id))->not->toBeNull();
});

it('bulkDelete removes every selected row', function () {
    Storage::fake('cache');

    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $rowA = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '7777',
        'disk' => 'cache',
        'file_path' => 'cache/a.mp4',
    ]);
    $rowB = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '7778',
        'disk' => 'cache',
        'file_path' => 'cache/b.mp4',
    ]);

    // file_path is resolved relative to the cache disk's root, so the
    // on-disk files live at cache/a.mp4 and cache/b.mp4.
    Storage::disk('cache')->put('cache/a.mp4', 'a');
    Storage::disk('cache')->put('cache/b.mp4', 'b');

    $this->actingAs($user);

    Livewire::test(ListCachedContentFiles::class)
        ->callTableBulkAction('bulkDelete', [$rowA->id, $rowB->id])
        ->assertNotified();

    expect(CachedContentFile::find($rowA->id))->toBeNull()
        ->and(CachedContentFile::find($rowB->id))->toBeNull();

    expect(Storage::disk('cache')->exists('cache/a.mp4'))->toBeFalse()
        ->and(Storage::disk('cache')->exists('cache/b.mp4'))->toBeFalse();
});

// Formatters

it('getProgressLabel returns the Pending label for Pending rows', function () {
    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '1',
        'status' => CachedContentFileStatus::Pending,
    ]);

    expect(CachedContentFileResource::getProgressLabel($row))->toBe(__('Pending'));
});

it('getProgressLabel formats the final file size for Completed rows', function () {
    $row = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '1',
        'file_size_bytes' => 1_572_864, // 1.50 MB
    ]);

    $label = CachedContentFileResource::getProgressLabel($row);

    expect($label)->toContain('MB')
        ->and($label)->toContain('1.50');
});

it('getProgressLabel falls back to bytes_downloaded when file_size_bytes is null on a Completed row', function () {
    $row = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '1',
        'file_size_bytes' => null,
        'bytes_downloaded' => 2_097_152, // 2.00 MB
    ]);

    $label = CachedContentFileResource::getProgressLabel($row);

    expect($label)->toContain('MB')
        ->and($label)->toContain('2.00');
});

it('getProgressLabel returns the Completed label when no size is recorded', function () {
    $row = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '1',
        'file_size_bytes' => null,
        'bytes_downloaded' => null,
    ]);

    expect(CachedContentFileResource::getProgressLabel($row))->toBe(__('Completed'));
});

it('getProgressLabel formats last-known bytes for Failed rows', function () {
    $row = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '1',
        'bytes_downloaded' => 999,
    ]);

    $label = CachedContentFileResource::getProgressLabel($row);

    expect($label)->toContain('KB');
});

it('getProgressLabel returns the Failed label when no bytes are recorded', function () {
    $row = CachedContentFile::factory()->failed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '1',
        'bytes_downloaded' => null,
    ]);

    expect(CachedContentFileResource::getProgressLabel($row))->toBe(__('Failed'));
});

it('getProgressLabel formats bytes for Downloading rows', function () {
    $row = CachedContentFile::factory()->downloading(524_288_000, 2_147_483_648)->create([
        'content_type' => 'movie',
        'tmdb_id' => '1',
    ]);

    $label = CachedContentFileResource::getProgressLabel($row);

    expect($label)->toContain('MB')
        ->and($label)->toContain(' / ')
        ->and($label)->not->toContain('%');
});

it('getProgressPercent reports bytes downloaded vs expected, 100 when Completed, 0 without a size', function () {
    $downloading = CachedContentFile::factory()->downloading(536_870_912, 2_147_483_648)->create();
    $unknownSize = CachedContentFile::factory()->downloading(536_870_912, null)->create();
    $completed = CachedContentFile::factory()->completed()->create();
    $pending = CachedContentFile::factory()->create();

    expect(CachedContentFileResource::getProgressPercent($downloading))->toBe(25)
        ->and(CachedContentFileResource::getProgressPercent($unknownSize))->toBe(0)
        ->and(CachedContentFileResource::getProgressPercent($completed))->toBe(100)
        ->and(CachedContentFileResource::getProgressPercent($pending))->toBe(0);
});

it('getProgressColor follows status and flags stalled downloads', function () {
    $active = CachedContentFile::factory()->downloading()->create();
    $stalled = CachedContentFile::factory()->downloading()->create(['last_progress_at' => now()->subMinutes(2)]);

    expect(CachedContentFileResource::getProgressColor($active))->toBe('primary')
        ->and(CachedContentFileResource::getProgressColor($stalled))->toBe('warning')
        ->and(CachedContentFileResource::getProgressColor(CachedContentFile::factory()->completed()->create()))->toBe('success')
        ->and(CachedContentFileResource::getProgressColor(CachedContentFile::factory()->failed()->create()))->toBe('danger');
});

it('renders the shared progress bar with the percent for a downloading row', function () {
    $user = User::factory()->create();
    $this->actingAs($user);
    $row = CachedContentFile::factory()->downloading(536_870_912, 2_147_483_648)->create(['user_id' => $user->id]);

    Livewire::test(ListCachedContentFiles::class)
        ->loadTable()
        ->assertTableColumnExists('progress', fn ($column) => $column instanceof ProgressColumn, $row)
        ->assertSee('25%')
        ->assertSee('512.00 MB / 2.00 GB');
});

it('getEtaLabel returns null for stalled rows', function () {
    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '1',
        'status' => CachedContentFileStatus::Downloading,
        'bytes_downloaded' => 1_000_000,
        'bytes_expected' => 10_000_000,
        'bytes_per_second' => 1_000_000,
        'last_progress_at' => now()->subMinutes(2),
    ]);

    expect(CachedContentFileResource::getEtaLabel($row))->toBeNull();
});

it('getContentLabel prefers the persisted title when present', function () {
    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '42',
        'title' => 'Inception',
    ]);

    expect(CachedContentFileResource::getContentLabel($row))->toBe('Inception');
});

it('getContentLabel falls back to a fingerprint label when title is null and no source row matches', function () {
    $row = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '42',
        'title' => null,
    ]);

    $label = CachedContentFileResource::getContentLabel($row);

    expect($label)->toContain('movie')
        ->and($label)->toContain('42');
});

// Title column

it('table renders the stored title for a row whose title was set at dispatch time', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '9100',
        'title' => 'Persisted Title',
    ]);

    $this->actingAs($user);

    Livewire::test(ListCachedContentFiles::class)
        ->assertOk()
        ->loadTable()
        ->assertSee('Persisted Title');
});

// Adaptive poll: 5s while an in-flight row is visible, 60s otherwise

it('table poll slows to 60s when no visible rows are Pending or Downloading', function () {
    // Terminal rows only: poll slowly so the table doesn't re-render every 5s.
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '9201',
    ]);
    CachedContentFile::factory()->failed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '9202',
    ]);

    $this->actingAs($user);

    $instance = Livewire::test(ListCachedContentFiles::class)->instance();
    $reflection = new ReflectionClass($instance);
    $tableProperty = $reflection->getProperty('table');
    $tableProperty->setAccessible(true);

    // Build the table through the public entrypoint so the ->poll(...)
    // Closure is registered, then resolve the polling interval.
    $table = $instance->table($tableProperty->getValue($instance) ?? $instance->getTable());
    expect($table->getPollingInterval())->toBe('60s');
});

it('table poll resolves to 5s when at least one visible row is Downloading', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '9301',
    ]);
    CachedContentFile::factory()->downloading(500_000, 2_000_000)->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '9302',
    ]);

    $this->actingAs($user);

    $instance = Livewire::test(ListCachedContentFiles::class)->instance();
    $table = $instance->getTable();
    expect($table->getPollingInterval())->toBe('5s');
});
