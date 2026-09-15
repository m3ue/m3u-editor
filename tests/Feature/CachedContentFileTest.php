<?php

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory()->create() fires PlaylistListener → SyncPipelineService
    // → dispatch(ProcessM3uImport). Bus::fake() catches that; ownership tests
    // never trigger the listener.
    Bus::fake();
});

// fingerprintFor() — locked 5-rule normalization contract:
//   1. content_type lowercased+trimmed, REQUIRED (throws if empty)
//   2. quality lowercased+trimmed
//   3. tmdb_id/tvdb_id coerced to string (null/missing → '')
//   4. season_number/episode_number (int)-cast then stringified (no leading zeros)
//   5. Separator: ':'
// Output format: content_type:tmdb_id:tvdb_id:season_number:episode_number:quality

it('builds a deterministic fingerprint for a movie', function () {
    // 6 fields separated by 5 colons; the 4 empty middle fields contribute
    // 4 empty positions between tmdb_id and quality.
    expect(CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
    ]))->toBe('movie:550::::1080p');
});

it('builds a deterministic fingerprint for an episode with season and episode', function () {
    expect(CachedContentFile::fingerprintFor([
        'content_type' => 'episode',
        'tmdb_id' => '60625',
        'season_number' => 1,
        'episode_number' => 5,
        'quality' => '4K',
    ]))->toBe('episode:60625::1:5:4k');
});

it('renders null/missing tvdb_id as empty in the fingerprint', function () {
    // Explicit null
    $withNull = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'tvdb_id' => null,
    ]);
    // Key absent
    $absent = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect($withNull)->toBe('movie:550::::')
        ->and($absent)->toBe('movie:550::::');
});

it('lowercases and trims quality values', function () {
    expect(CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '  4K  ',
    ]))->toBe('movie:550::::4k');
});

it('lowercases and trims content_type', function () {
    $fingerprint = CachedContentFile::fingerprintFor([
        'content_type' => ' MOVIE ',
        'tmdb_id' => '550',
    ]);

    expect($fingerprint)->toStartWith('movie:550');
});

it('throws InvalidArgumentException when content_type is an empty string', function () {
    expect(fn () => CachedContentFile::fingerprintFor(['content_type' => '']))
        ->toThrow(InvalidArgumentException::class);
});

it('throws InvalidArgumentException when content_type key is missing', function () {
    expect(fn () => CachedContentFile::fingerprintFor(['tmdb_id' => '550']))
        ->toThrow(InvalidArgumentException::class);
});

it('casts season_number as int then string (no leading zeros)', function () {
    // Without leading-zero padding — season 7 becomes '7', not '07'.
    expect(CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'season_number' => 7,
    ]))->toBe('movie:::7::');
});

it('coerces integer tmdb_id to string in the fingerprint', function () {
    // Even if the caller passes an int (mirroring episodes.tmdb_id which is integer-typed),
    // the fingerprint must be string-stable.
    expect(CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => 123,
    ]))->toBe('movie:123::::');
});

it('produces the same fingerprint regardless of field insertion order', function () {
    $ordered = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
    ]);
    $reversed = CachedContentFile::fingerprintFor([
        'quality' => '1080p',
        'tmdb_id' => '550',
        'content_type' => 'movie',
    ]);

    expect($ordered)->toBe($reversed);
});

// Creating event — empty content_type rejection

it('rejects empty content_type in the creating event', function () {
    expect(fn () => CachedContentFile::factory()->create(['content_type' => '']))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects whitespace-only content_type in the creating event (normalized to empty)', function () {
    expect(fn () => CachedContentFile::factory()->create(['content_type' => '   ']))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts factory defaults without an explicit content_type', function () {
    $file = CachedContentFile::factory()->create();

    expect($file->content_type)->toBe('movie');
});

// Creating event — fingerprint auto-derivation

it('auto-derives content_fingerprint from parts in the creating event', function () {
    $file = CachedContentFile::factory()->forMovie('550')->create();

    expect($file->content_fingerprint)->toBe('movie:550::::1080p');
});

it('auto-generates uuid in the creating event when not supplied', function () {
    // Phase 2's download service will call CachedContentFile::create([...]) /
    // firstOrCreate(...) without a pre-supplied uuid. The `creating` boot
    // must populate it so the NOT NULL uuid column doesn't reject the row
    // (mirrors DvrRecording's boot convention at app/Models/DvrRecording.php:56-57).
    // Direct ::create() bypasses the factory's definition(), so this exercises
    // the production-style path. uuid is intentionally NOT in $fillable, so
    // the mass-assignment drop doesn't matter here.
    $file = CachedContentFile::create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect($file->uuid)->not->toBeEmpty()
        ->and($file->uuid)->toBeString();
});

it('auto-derives episode fingerprint with season and episode', function () {
    $file = CachedContentFile::factory()->forEpisode('60625', 1, 5)->create();

    expect($file->content_fingerprint)->toBe('episode:60625::1:5:1080p');
});

// hasFilePath()

it('hasFilePath returns false for pending files with no file_path', function () {
    $file = CachedContentFile::factory()->create(['file_path' => null]);

    expect($file->hasFilePath())->toBeFalse();
});

it('hasFilePath returns true for completed files with file_path', function () {
    $file = CachedContentFile::factory()->completed()->create();

    expect($file->hasFilePath())->toBeTrue();
});

it('hasFilePath returns false for completed files with empty file_path', function () {
    $file = CachedContentFile::factory()->completed()->create(['file_path' => '']);

    expect($file->hasFilePath())->toBeFalse();
});

it('hasFilePath returns false for failed files even with file_path', function () {
    // hasFilePath requires status === Completed, regardless of file_path.
    $file = CachedContentFile::factory()->failed()->create(['file_path' => 'cache/foo.mp4']);

    expect($file->hasFilePath())->toBeFalse();
});

// resolveStorageDisk()

it('returns the file disk when set', function () {
    $file = CachedContentFile::factory()->completed()->create(['disk' => 's3']);

    expect($file->resolveStorageDisk())->toBe('s3');
});

it('falls back to config(filesystems.default) when disk is null', function () {
    $file = CachedContentFile::factory()->create(['disk' => null]);

    expect($file->resolveStorageDisk())->toBe(config('filesystems.default'));
});

// resolveMimeType()

it('returns video/mp4 for .mp4 files', function () {
    $file = CachedContentFile::factory()->create(['file_path' => 'cache/foo.mp4']);

    expect($file->resolveMimeType())->toBe('video/mp4');
});

it('returns video/x-matroska for .mkv files', function () {
    $file = CachedContentFile::factory()->create(['file_path' => 'cache/foo.mkv']);

    expect($file->resolveMimeType())->toBe('video/x-matroska');
});

it('returns video/mp2t as the default for unknown extensions', function () {
    $file = CachedContentFile::factory()->create(['file_path' => 'cache/foo.ts']);

    expect($file->resolveMimeType())->toBe('video/mp2t');
});

it('returns video/mp2t as the default for null file_path', function () {
    $file = CachedContentFile::factory()->create(['file_path' => null]);

    expect($file->resolveMimeType())->toBe('video/mp2t');
});

// Uniqueness — DB-level guard on content_fingerprint (no service-layer firstOrCreate in Phase 1).
// Phase 2's download service will use firstOrCreate against this column.

it('rejects a second CachedContentFile with the same content_fingerprint', function () {
    CachedContentFile::factory()->forMovie('550')->create();

    expect(fn () => CachedContentFile::factory()->forMovie('550')->create())
        ->toThrow(QueryException::class);
});

// Enum sanity — also covered by the boot tests above; one explicit test for the enum mapping.

it('maps content_fingerprint status to the CachedContentFileStatus enum', function () {
    $pending = CachedContentFile::factory()->create();
    $completed = CachedContentFile::factory()->completed()->create();
    $failed = CachedContentFile::factory()->failed()->create();

    expect($pending->status)->toBe(CachedContentFileStatus::Pending)
        ->and($completed->status)->toBe(CachedContentFileStatus::Completed)
        ->and($failed->status)->toBe(CachedContentFileStatus::Failed);
});

// --- scopeOwnedByUser() — per-user ownership filter on the shared cache table ---

/**
 * Seed two users + playlists + groups, each with their own cached file.
 * Returned as [$userA, $userB, $playlistA, $playlistB, $groupA, $groupB, $fileA, $fileB].
 *
 * @return array{0: User, 1: User, 2: Playlist, 3: Playlist, 4: DynamicGroup, 5: DynamicGroup, 6: CachedContentFile, 7: CachedContentFile}
 */
function seedTwoUsersWithCacheFiles(): array
{
    $userA = User::factory()->create(['is_admin' => false]);
    $userB = User::factory()->create(['is_admin' => false]);
    $playlistA = Playlist::factory()->for($userA)->create();
    $playlistB = Playlist::factory()->for($userB)->create();
    $groupA = DynamicGroup::create([
        'playlist_id' => $playlistA->id, 'user_id' => $userA->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Group A',
    ]);
    $groupB = DynamicGroup::create([
        'playlist_id' => $playlistB->id, 'user_id' => $userB->id,
        'type' => 'vod', 'source' => 'trending', 'name' => 'Group B',
    ]);
    $fileA = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '550',
    ]);
    $fileB = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '551',
    ]);
    $fileA->dynamicGroups()->attach($groupA);
    $fileB->dynamicGroups()->attach($groupB);

    return [$userA, $userB, $playlistA, $playlistB, $groupA, $groupB, $fileA, $fileB];
}

it('scopeOwnedByUser() returns all rows when adminBypass is true', function () {
    [$userA, $userB, , , , , $fileA, $fileB] = seedTwoUsersWithCacheFiles();
    $admin = User::factory()->create(['is_admin' => true]);

    $ids = CachedContentFile::query()
        ->ownedByUser($admin, adminBypass: true)
        ->pluck('id')
        ->all();

    expect($ids)->toContain($fileA->id, $fileB->id);
});

it('scopeOwnedByUser() returns all rows when $user is null (defensive fallback)', function () {
    [$userA, $userB, , , , , $fileA, $fileB] = seedTwoUsersWithCacheFiles();

    $ids = CachedContentFile::query()
        ->ownedByUser(null)
        ->pluck('id')
        ->all();

    expect($ids)->toContain($fileA->id, $fileB->id);
});

it('scopeOwnedByUser() returns only the given user\'s rows when not admin-bypassed', function () {
    [$userA, $userB, , , , , $fileA, $fileB] = seedTwoUsersWithCacheFiles();

    $idsA = CachedContentFile::query()
        ->ownedByUser($userA)
        ->pluck('id')
        ->all();
    $idsB = CachedContentFile::query()
        ->ownedByUser($userB)
        ->pluck('id')
        ->all();

    expect($idsA)->toContain($fileA->id)
        ->and($idsA)->not->toContain($fileB->id)
        ->and($idsB)->toContain($fileB->id)
        ->and($idsB)->not->toContain($fileA->id);
});

it('scopeOwnedByUser() returns no rows for a non-admin who owns no playlists', function () {
    seedTwoUsersWithCacheFiles();
    $stranger = User::factory()->create(['is_admin' => false]);

    $count = CachedContentFile::query()
        ->ownedByUser($stranger)
        ->count();

    expect($count)->toBe(0);
});

it('scopeOwnedByUser() follows the pivot: a file referenced by ANY of the user\'s groups counts as owned', function () {
    // File A is referenced by both user A's group AND user B's group. Both
    // users should see it — the scope is OR, not exclusive. This is the
    // whole point of the pivot pattern: a shared download serves both.
    // File B remains only on user B's group, so user A still doesn't see it.
    [$userA, $userB, , , , $groupB, $fileA, $fileB] = seedTwoUsersWithCacheFiles();
    $fileA->dynamicGroups()->attach($groupB);

    $idsA = CachedContentFile::query()->ownedByUser($userA)->pluck('id')->all();
    $idsB = CachedContentFile::query()->ownedByUser($userB)->pluck('id')->all();

    // User A owns fileA via groupA; fileB is groupB-only (B's).
    expect($idsA)->toContain($fileA->id)
        ->and($idsA)->not->toContain($fileB->id)
        // User B owns both: fileA via the new shared pivot row + fileB via groupB.
        ->and($idsB)->toContain($fileA->id, $fileB->id);
});

it('scopeOwnedByUser() returns no rows for an orphan file (no DynamicGroup memberships)', function () {
    $user = User::factory()->create(['is_admin' => false]);
    $orphan = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie', 'tmdb_id' => '999',
    ]);

    $count = CachedContentFile::query()
        ->ownedByUser($user)
        ->count();

    expect($count)->toBe(0)
        ->and(CachedContentFile::find($orphan->id))->not->toBeNull();
});
