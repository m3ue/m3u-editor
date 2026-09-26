<?php

use App\Enums\CachedContentFileStatus;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Models\Playlist;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory()->create() fires PlaylistListener -> SyncPipelineService
    // -> dispatch(ProcessM3uImport). Bus::fake() catches that; ownership tests
    // never trigger the listener.
    Bus::fake();
});

// fingerprintFor() - locked 5-rule normalization contract:
//   1. content_type lowercased+trimmed, REQUIRED (throws if empty)
//   2. quality lowercased+trimmed
//   3. tmdb_id/tvdb_id coerced to string (null/missing -> '')
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
    // Without leading-zero padding - season 7 becomes '7', not '07'.
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

// Creating event - empty content_type rejection

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

// Creating event - fingerprint auto-derivation

it('auto-derives content_fingerprint from parts in the creating event', function () {
    $file = CachedContentFile::factory()->forMovie('550', '1080p')->create();

    expect($file->content_fingerprint)->toBe('movie:550::::1080p');
});

it('auto-generates uuid in the creating event when not supplied', function () {
    // The dispatcher calls CachedContentFile::create([...]) without a uuid;
    // the `creating` hook must fill it.
    $channel = Channel::factory()->create(['is_vod' => true]);
    $file = CachedContentFile::create([
        'cacheable_type' => $channel->getMorphClass(),
        'cacheable_id' => $channel->id,
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);

    expect($file->uuid)->not->toBeEmpty()
        ->and($file->uuid)->toBeString();
});

it('auto-derives episode fingerprint with season and episode', function () {
    $file = CachedContentFile::factory()->forEpisode('60625', 1, 5, '1080p')->create();

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

it('falls back to the cache disk when disk is null', function () {
    $file = CachedContentFile::factory()->create(['disk' => null]);

    expect($file->resolveStorageDisk())->toBe(CachedContentFile::DISK);
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

// Uniqueness - one cached file per source item.

it('rejects a second CachedContentFile for the same channel', function () {
    $file = CachedContentFile::factory()->create();

    expect(fn () => CachedContentFile::factory()->create([
        'user_id' => $file->user_id,
        'playlist_id' => $file->playlist_id,
        'cacheable_type' => $file->cacheable_type,
        'cacheable_id' => $file->cacheable_id,
    ]))->toThrow(QueryException::class);
});

it('allows two channels with the same TMDB id in one playlist to each have a cached file', function () {
    // e.g. a German and an English release of the same film.
    $playlist = Playlist::factory()->create();
    $german = Channel::factory()->create(['playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'is_vod' => true, 'tmdb_id' => 550]);
    $english = Channel::factory()->create(['playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'is_vod' => true, 'tmdb_id' => 550]);

    CachedContentFile::factory()->forItem($german)->create();
    CachedContentFile::factory()->forItem($english)->create();

    expect(CachedContentFile::count())->toBe(2)
        ->and($german->cachedContentFile)->not->toBeNull()
        ->and($english->cachedContentFile->id)->not->toBe($german->cachedContentFile->id);
});

// --- fingerprintFor(): local_key (PR #1524 review item 6) ---
//
// The local_key disambiguator kicks in ONLY when both tmdb_id and
// tvdb_id are empty (no external identity to match on). Matched
// fingerprints stay byte-identical to the prior contract so the
// existing test expectations above all still hold.

it('appends local_key to fingerprint when both tmdb_id and tvdb_id are empty', function () {
    $fp = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'local_key' => 'ch42',
    ]);

    expect($fp)->toBe('movie::::::ch42');
});

it('does NOT append local_key when tmdb_id is set (matched content fingerprint stays unchanged)', function () {
    // The local_key segment must only appear for UNmatched content -
    // matched fingerprints must stay byte-identical to the prior
    // contract or existing rows / cache files would not match.
    $fp = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => '1080p',
        'local_key' => 'ch42',
    ]);

    expect($fp)->toBe('movie:550::::1080p');
});

it('does NOT append local_key when tvdb_id alone is set', function () {
    $fp = CachedContentFile::fingerprintFor([
        'content_type' => 'movie',
        'tvdb_id' => '99',
        'quality' => '1080p',
        'local_key' => 'ch42',
    ]);

    expect($fp)->toBe('movie::99:::1080p');
});

// Enum sanity - also covered by the boot tests above; one explicit test for the enum mapping.

it('maps content_fingerprint status to the CachedContentFileStatus enum', function () {
    $pending = CachedContentFile::factory()->create();
    $completed = CachedContentFile::factory()->completed()->create();
    $failed = CachedContentFile::factory()->failed()->create();

    expect($pending->status)->toBe(CachedContentFileStatus::Pending)
        ->and($completed->status)->toBe(CachedContentFileStatus::Completed)
        ->and($failed->status)->toBe(CachedContentFileStatus::Failed);
});

// --- scopeOwnedBy() ---

/**
 * Seed two users each with their own playlist and one cached file.
 * Returned as [$userA, $userB, $playlistA, $playlistB, $fileA, $fileB].
 *
 * @return array{0: User, 1: User, 2: Playlist, 3: Playlist, 4: CachedContentFile, 5: CachedContentFile}
 */
function seedTwoUsersWithCacheFiles(): array
{
    $userA = User::factory()->create(['is_admin' => false]);
    $userB = User::factory()->create(['is_admin' => false]);
    $playlistA = Playlist::factory()->for($userA)->create();
    $playlistB = Playlist::factory()->for($userB)->create();
    $fileA = CachedContentFile::factory()->completed()->create([
        'user_id' => $userA->id,
        'playlist_id' => $playlistA->id,
        'content_type' => 'movie',
        'tmdb_id' => '550',
    ]);
    $fileB = CachedContentFile::factory()->completed()->create([
        'user_id' => $userB->id,
        'playlist_id' => $playlistB->id,
        'content_type' => 'movie',
        'tmdb_id' => '551',
    ]);

    return [$userA, $userB, $playlistA, $playlistB, $fileA, $fileB];
}

it('scopeOwnedBy() returns only rows for the given user_id', function () {
    [$userA, $userB, , , $fileA, $fileB] = seedTwoUsersWithCacheFiles();

    $idsA = CachedContentFile::query()->ownedBy($userA->id)->pluck('id')->all();
    $idsB = CachedContentFile::query()->ownedBy($userB->id)->pluck('id')->all();

    expect($idsA)->toContain($fileA->id)
        ->and($idsA)->not->toContain($fileB->id)
        ->and($idsB)->toContain($fileB->id)
        ->and($idsB)->not->toContain($fileA->id);
});

it('scopeOwnedBy() returns no rows for a user who owns nothing', function () {
    seedTwoUsersWithCacheFiles();
    $stranger = User::factory()->create(['is_admin' => false]);

    $count = CachedContentFile::query()->ownedBy($stranger->id)->count();

    expect($count)->toBe(0);
});

it('scopeOwnedBy() excludes NULL user_id rows (orphan-safe)', function () {
    // Pre-PR-A historical rows have user_id IS NULL and must not leak
    // through a user-scoped query - they have no claimable owner.
    CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '999',
        'user_id' => null,
        'playlist_id' => null,
    ]);
    $user = User::factory()->create();

    $count = CachedContentFile::query()->ownedBy($user->id)->count();

    expect($count)->toBe(0)
        ->and(CachedContentFile::count())->toBe(1);  // the orphan row still exists
});

// --- findServableFor() / scopeSharedWithPlaylist() ---

/**
 * One user with two playlists, each with a VOD channel for TMDB 550.
 *
 * @return array{0: Playlist, 1: Playlist, 2: Channel, 3: Channel}
 */
function seedSiblingPlaylistsWithSameMovie(bool $shareFromA): array
{
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create(['share_cache_across_playlists' => $shareFromA]);
    $playlistB = Playlist::factory()->for($user)->create();
    $channelA = Channel::factory()->create(['user_id' => $user->id, 'playlist_id' => $playlistA->id, 'is_vod' => true, 'tmdb_id' => 550]);
    $channelB = Channel::factory()->create(['user_id' => $user->id, 'playlist_id' => $playlistB->id, 'is_vod' => true, 'tmdb_id' => 550]);

    return [$playlistA, $playlistB, $channelA, $channelB];
}

it('findServableFor() returns the item own Completed row', function () {
    [, , $channelA] = seedSiblingPlaylistsWithSameMovie(false);
    $file = CachedContentFile::factory()->completed()->forItem($channelA)->create();

    expect(CachedContentFile::findServableFor($channelA)?->id)->toBe($file->id);
});

it('findServableFor() ignores rows that are not Completed', function () {
    [, , $channelA] = seedSiblingPlaylistsWithSameMovie(false);
    CachedContentFile::factory()->downloading()->forItem($channelA)->create();

    expect(CachedContentFile::findServableFor($channelA))->toBeNull();
});

it('findServableFor() uses a sibling playlist copy when that playlist shares its cache', function () {
    [, , $channelA, $channelB] = seedSiblingPlaylistsWithSameMovie(true);
    $file = CachedContentFile::factory()->completed()->forItem($channelA)->create();

    expect(CachedContentFile::findServableFor($channelB)?->id)->toBe($file->id);
});

it('findServableFor() does not use a sibling playlist copy when sharing is off', function () {
    [, , $channelA, $channelB] = seedSiblingPlaylistsWithSameMovie(false);
    CachedContentFile::factory()->completed()->forItem($channelA)->create();

    expect(CachedContentFile::findServableFor($channelB))->toBeNull();
});

it('findServableFor() never uses another user copy, even with sharing on', function () {
    $owner = Playlist::factory()->create(['share_cache_across_playlists' => true]);
    $other = Playlist::factory()->create();
    $ownerChannel = Channel::factory()->create(['user_id' => $owner->user_id, 'playlist_id' => $owner->id, 'is_vod' => true, 'tmdb_id' => 550]);
    $otherChannel = Channel::factory()->create(['user_id' => $other->user_id, 'playlist_id' => $other->id, 'is_vod' => true, 'tmdb_id' => 550]);
    CachedContentFile::factory()->completed()->forItem($ownerChannel)->create();

    expect(CachedContentFile::findServableFor($otherChannel))->toBeNull();
});

it('findServableFor() does not serve a same-TMDB channel in the same playlist', function () {
    // Two releases of one film in one playlist must not share a file.
    $playlist = Playlist::factory()->create(['share_cache_across_playlists' => true]);
    $german = Channel::factory()->create(['playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'is_vod' => true, 'tmdb_id' => 550]);
    $english = Channel::factory()->create(['playlist_id' => $playlist->id, 'user_id' => $playlist->user_id, 'is_vod' => true, 'tmdb_id' => 550]);
    CachedContentFile::factory()->completed()->forItem($german)->create();

    expect(CachedContentFile::findServableFor($english))->toBeNull();
});

it('findServableFor() prefers the item own row over a shared copy', function () {
    [, , $channelA, $channelB] = seedSiblingPlaylistsWithSameMovie(true);
    CachedContentFile::factory()->completed()->forItem($channelA)->create();
    $own = CachedContentFile::factory()->completed()->forItem($channelB)->create();

    expect(CachedContentFile::findServableFor($channelB)?->id)->toBe($own->id);
});

// --- isPlayable() / storage helpers ---

it('isPlayable() is true only when the Completed file exists on disk', function () {
    Storage::fake(CachedContentFile::DISK);
    $file = CachedContentFile::factory()->completed()->create(['disk' => CachedContentFile::DISK]);

    expect($file->isPlayable())->toBeFalse();

    Storage::disk(CachedContentFile::DISK)->put($file->file_path, 'bytes');

    expect($file->isPlayable())->toBeTrue();
});

it('storagePathFor() gives every row its own path, grouped by playlist', function () {
    $a = CachedContentFile::factory()->create();
    $b = CachedContentFile::factory()->create(['playlist_id' => $a->playlist_id, 'user_id' => $a->user_id]);

    expect($a->storagePathFor('mkv'))->toBe($a->playlist_id.'/'.$a->uuid.'.mkv')
        ->and($a->storagePathFor('.mp4'))->not->toBe($b->storagePathFor('.mp4'));
});

it('deleteStoredFile() removes the file from the disk', function () {
    Storage::fake(CachedContentFile::DISK);
    $file = CachedContentFile::factory()->completed()->create();
    Storage::disk(CachedContentFile::DISK)->put($file->file_path, 'bytes');

    $file->deleteStoredFile();

    Storage::disk(CachedContentFile::DISK)->assertMissing($file->file_path);
});

// --- playlist() relation ---

it('playlist() resolves to the source playlist', function () {
    [, , $playlistA, , $fileA] = seedTwoUsersWithCacheFiles();

    expect($fileA->playlist)->not->toBeNull()
        ->and($fileA->playlist->is($playlistA))->toBeTrue();
});

it('playlist() returns null when playlist_id is null', function () {
    $file = CachedContentFile::factory()->create(['playlist_id' => null, 'user_id' => null]);

    expect($file->playlist)->toBeNull();
});

// --- $fillable: error message and ownership columns are mass assignable ---

it('last_error_message survives mass-assigned update()', function () {
    $file = CachedContentFile::factory()->create();

    $file->update(['last_error_message' => 'connection refused by upstream proxy']);

    expect($file->fresh()->last_error_message)->toBe('connection refused by upstream proxy');
});

it('user_id and playlist_id survive mass-assigned update()', function () {
    $file = CachedContentFile::factory()->create(['user_id' => null, 'playlist_id' => null]);
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $file->update(['user_id' => $user->id, 'playlist_id' => $playlist->id]);

    $fresh = $file->fresh();
    expect($fresh->user_id)->toBe($user->id)
        ->and($fresh->playlist_id)->toBe($playlist->id);
});

// --- factory default-stamps user_id + playlist_id ---

it('factory definition() defaults user_id and playlist_id', function () {
    $file = CachedContentFile::factory()->create();

    expect($file->user_id)->not->toBeNull()
        ->and($file->playlist_id)->not->toBeNull();
});

it('stores season and episode numbers wider than a smallint', function () {
    $file = CachedContentFile::factory()->forEpisode('1399', 2024, 20240101)->create();

    expect($file->fresh())
        ->season_number->toBe(2024)
        ->episode_number->toBe(20240101);
});
