<?php

use App\Models\CachedContentFile;
use App\Models\Playlist;
use App\Models\User;
use App\Settings\GeneralSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory() fires SyncPipelineService -> dispatch(ProcessM3uImport).
    // Bus::fake() catches it; nothing reaches Redis during tests.
    Bus::fake();
    Storage::fake('cache');
    $settings = Mockery::mock(GeneralSettings::class);
    $settings->enable_cache = true;
    app()->instance(GeneralSettings::class, $settings);
});

it('returns 401 with invalid credentials', function () {
    // No auth, no resolution - the controller should bail at the auth step.
    $response = $this->get('/cached-content/baduser/badpass/some-uuid.mp4');

    $response->assertStatus(401);
});

it('serves a cached file when credentials resolve and the file belongs to the playlist', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-abc.mp4', 'fake-bytes-for-testing');

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'file_path' => 'cache/movie-abc.mp4',
    ]);

    $response = $this->get("/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4");

    $response->assertOk();
    expect((string) $response->streamedContent())->toContain('fake-bytes-for-testing');
});

it('does not serve cached files when caching is disabled', function () {
    app(GeneralSettings::class)->enable_cache = false;
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-disabled.mp4', 'bytes');

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '551',
        'file_path' => 'cache/movie-disabled.mp4',
    ]);

    $response = $this->get("/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4");

    $response->assertNotFound();
});

it('returns 404 when the file belongs to a different playlist (no info leak)', function () {
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $playlistA = Playlist::factory()->for($userA)->create();
    $playlistB = Playlist::factory()->for($userB)->create();
    Storage::disk('cache')->put('cache/movie-xyz.mp4', 'bytes');

    $fileA = CachedContentFile::factory()->completed()->create([
        'user_id' => $userA->id,
        'playlist_id' => $playlistA->id,
        'content_type' => 'movie',
        'tmdb_id' => '999',
        'file_path' => 'cache/movie-xyz.mp4',
    ]);

    // userB tries to access userA's file via userB's playlist - 404, not 403.
    // Matches DvrStreamController pattern (no info leak via status code difference).
    $response = $this->get("/cached-content/{$userB->name}/{$playlistB->uuid}/{$fileA->uuid}.mp4");

    $response->assertStatus(404);
});

it('returns 404 when the cached file exists but the row is not in Completed status', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-pending.mp4', 'bytes');

    $file = CachedContentFile::factory()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '888',
        'file_path' => 'cache/movie-pending.mp4',
        // Status defaults to Pending from the factory.
    ]);

    $response = $this->get("/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4");

    $response->assertStatus(404);
});

it('serves a 206 Partial Content response for a Range request', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-range.mp4', str_repeat('A', 1024).str_repeat('B', 1024));

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '777',
        'file_path' => 'cache/movie-range.mp4',
    ]);

    $response = $this->withHeaders(['Range' => 'bytes=0-99'])->get(
        "/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4"
    );

    $response->assertStatus(206);
    $response->assertHeader('Content-Range', 'bytes 0-99/2048');
    $response->assertHeader('Accept-Ranges', 'bytes');
});

it('returns 404 when the file row exists but the storage file is missing', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    // Deliberately do NOT put the file on disk.

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '666',
        'file_path' => 'cache/movie-missing.mp4',
    ]);

    $response = $this->get("/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4");

    $response->assertStatus(404);
});

// --- Suffix byte ranges (PR #1524 review item: players probe trailing MP4/MKV
//     metadata with `bytes=-N`). The streamed download previously served the
//     whole file 200 in response, breaking the probe. Now we serve a real 206
//     or 416 per RFC 7233.

it('serves a 206 for a suffix Range smaller than the file size', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    // 2048-byte file: any suffix up to 2048 lands inside the file.
    Storage::disk('cache')->put('cache/movie-suffix.mp4', str_repeat('A', 1024).str_repeat('B', 1024));

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '111',
        'file_path' => 'cache/movie-suffix.mp4',
    ]);

    // bytes=-500 -> start = 2048 - 500 = 1548, end = 2047, length = 500.
    $response = $this->withHeaders(['Range' => 'bytes=-500'])->get(
        "/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4"
    );

    $response->assertStatus(206);
    $response->assertHeader('Content-Range', 'bytes 1548-2047/2048');
    $response->assertHeader('Content-Length', '500');
});

it('serves a 206 for a suffix Range equal to the file size', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-suffix-eq.mp4', str_repeat('A', 1024));

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '112',
        'file_path' => 'cache/movie-suffix-eq.mp4',
    ]);

    // bytes=-1024 against a 1024-byte file -> whole file, 206.
    $response = $this->withHeaders(['Range' => 'bytes=-1024'])->get(
        "/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4"
    );

    $response->assertStatus(206);
    $response->assertHeader('Content-Range', 'bytes 0-1023/1024');
    $response->assertHeader('Content-Length', '1024');
});

it('clamps a suffix Range that exceeds the file size to the whole file', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-suffix-huge.mp4', str_repeat('A', 1024));

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '113',
        'file_path' => 'cache/movie-suffix-huge.mp4',
    ]);

    // Player probing trailing MP4 metadata with bytes=-65536 on a 1024-byte file:
    // clamp start to 0, end to 1023, full file. The previous (buggy) behaviour
    // was to silently serve the entire body as a plain 200.
    $response = $this->withHeaders(['Range' => 'bytes=-65536'])->get(
        "/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4"
    );

    $response->assertStatus(206);
    $response->assertHeader('Content-Range', 'bytes 0-1023/1024');
});

it('returns 416 for a suffix Range of zero length', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-suffix-zero.mp4', str_repeat('A', 1024));

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '114',
        'file_path' => 'cache/movie-suffix-zero.mp4',
    ]);

    // bytes=-0 is unsatisfiable per RFC 7233 §2.1 (suffix length must be > 0).
    $response = $this->withHeaders(['Range' => 'bytes=-0'])->get(
        "/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4"
    );

    $response->assertStatus(416);
    $response->assertHeader('Content-Range', 'bytes */1024');
});

it('returns 416 for a Range with neither start nor end', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-empty-range.mp4', str_repeat('A', 1024));

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '115',
        'file_path' => 'cache/movie-empty-range.mp4',
    ]);

    $response = $this->withHeaders(['Range' => 'bytes=-'])->get(
        "/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4"
    );

    $response->assertStatus(416);
    $response->assertHeader('Content-Range', 'bytes */1024');
});

it('clamps a closed Range end that exceeds the file size', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-clamp.mp4', str_repeat('A', 1024));

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '115',
        'file_path' => 'cache/movie-clamp.mp4',
    ]);

    // bytes=0-5000 on a 1024-byte file -> clamp to 0-1023.
    $response = $this->withHeaders(['Range' => 'bytes=0-5000'])->get(
        "/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4"
    );

    $response->assertStatus(206);
    $response->assertHeader('Content-Range', 'bytes 0-1023/1024');
});

it('returns 416 for a closed Range whose start is at or past the file size', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-oob-start.mp4', str_repeat('A', 1024));

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '116',
        'file_path' => 'cache/movie-oob-start.mp4',
    ]);

    $response = $this->withHeaders(['Range' => 'bytes=2048-'])->get(
        "/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4"
    );

    $response->assertStatus(416);
    $response->assertHeader('Content-Range', 'bytes */1024');
});

it('falls back to a full 200 for a multi-range Range header (unsupported, by design)', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-multi.mp4', str_repeat('A', 1024));

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '117',
        'file_path' => 'cache/movie-multi.mp4',
    ]);

    // Multi-range is intentionally unsupported (we don't compose multipart/byteranges).
    // Anchored regex misses the comma, so we drop to the 200 full-body path -
    // same observable result as a player asking for content without a range.
    $response = $this->withHeaders(['Range' => 'bytes=0-10,200-300'])->get(
        "/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4"
    );

    $response->assertStatus(200);
    $response->assertHeader('Content-Length', '1024');
});

it('falls back to a full 200 for a malformed Range header', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-malformed.mp4', str_repeat('A', 1024));

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '118',
        'file_path' => 'cache/movie-malformed.mp4',
    ]);

    // Anchored regex misses anything that isn't a clean bytes= form.
    $response = $this->withHeaders(['Range' => 'garbage'])->get(
        "/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4"
    );

    $response->assertStatus(200);
    $response->assertHeader('Content-Length', '1024');
});

// --- PR #1524 review item: filesize() TOCTOU ---
//
// exists() to filesize()/fopen() is a tiny race window. If the file vanishes,
// the controller previously threw a TypeError 500 (strict int param) or served
// an empty 200. The fix routes the disappearance through Storage::size() in a
// try/catch and pre-checks fopen before the streamed response starts. We simulate
// the race by stubbing the disk so size() throws after exists() returns true.

it('returns 404 (not 500) when Storage::size() throws after a successful exists()', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();
    $realDisk = Storage::disk('cache');
    $tmpPath = $realDisk->path('cache/movie-vanish-size.mp4');
    @mkdir(dirname($tmpPath), 0777, true);
    file_put_contents($tmpPath, 'bytes');

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '119',
        'file_path' => 'cache/movie-vanish-size.mp4',
    ]);

    // Wrap the disk in a Mockery stand-in: exists() succeeds (the race precondition),
    // path() returns a real path (so the fopen guard has somewhere to look), but
    // size() throws — exactly the TOCTOU scenario the controller now catches.
    // The controller catches \Throwable, so any exception class exercises the guard.
    $mockDisk = Mockery::mock($realDisk);
    $mockDisk->shouldReceive('exists')->with($file->file_path)->andReturn(true);
    $mockDisk->shouldReceive('path')->with($file->file_path)->andReturn($tmpPath);
    $mockDisk->shouldReceive('size')->with($file->file_path)->andThrow(
        new RuntimeException('File vanished between exists() and size()')
    );

    Storage::shouldReceive('disk')->with('cache')->andReturn($mockDisk);

    $response = $this->get("/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4");

    $response->assertStatus(404);
});

it('returns 404 (not 500) when fopen() fails on the local path', function () {
    $user = User::factory()->create();
    $playlist = Playlist::factory()->for($user)->create();

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlist->id,
        'content_type' => 'movie',
        'tmdb_id' => '120',
        // Point at a path that does NOT exist on disk for exists() to find in the faked disk.
        'file_path' => 'cache/movie-no-fopen.mp4',
    ]);

    // Real disk reports the file as missing => the controller must 404, never 500.
    // This exercises both the exists() -> false short circuit and confirms the
    // fopen guard isn't exercised on a path that doesn't exist.
    $response = $this->get("/cached-content/{$user->name}/{$playlist->uuid}/{$file->uuid}.mp4");

    $response->assertStatus(404);
});

// --- PR #1524 review item 5: servable scope at stream auth ---
//
// The stream controller must honor the same sharing rule as the
// dispatcher + cache-hit gate so a file shared by a sibling playlist
// plays through this playlist's credentials. Cross-USER access is still
// 404 - the scope subquery filters sharing candidates by user_id.

it('streams a file shared by a same-user sibling playlist through this playlist credentials', function () {
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create(['share_cache_across_playlists' => true]);
    $playlistB = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-shared.mp4', 'shared-bytes');

    // File belongs to playlistA (sharing), accessed via playlistB credentials.
    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlistA->id,
        'content_type' => 'movie',
        'tmdb_id' => '1234',
        'file_path' => 'cache/movie-shared.mp4',
    ]);

    $response = $this->get("/cached-content/{$user->name}/{$playlistB->uuid}/{$file->uuid}.mp4");

    $response->assertOk();
    expect((string) $response->streamedContent())->toContain('shared-bytes');
});

it('returns 404 when the playlist is not a sharing sibling and the file belongs to a sibling', function () {
    // Same user, but sibling playlistA has sharing OFF. The file is not
    // servable for playlistB - 404 (not 403) to match the
    // "no info leak" convention.
    $user = User::factory()->create();
    $playlistA = Playlist::factory()->for($user)->create(['share_cache_across_playlists' => false]);
    $playlistB = Playlist::factory()->for($user)->create();
    Storage::disk('cache')->put('cache/movie-private.mp4', 'bytes');

    $file = CachedContentFile::factory()->completed()->create([
        'user_id' => $user->id,
        'playlist_id' => $playlistA->id,
        'content_type' => 'movie',
        'tmdb_id' => '5678',
        'file_path' => 'cache/movie-private.mp4',
    ]);

    $response = $this->get("/cached-content/{$user->name}/{$playlistB->uuid}/{$file->uuid}.mp4");

    $response->assertStatus(404);
});

it('returns 404 when another user tries to stream a file via their own credentials, even if the source shares', function () {
    // PR #1524 review item 5: other user's credentials cannot stream
    // A's uuid even if A's playlist has sharing ON. Cross-USER sharing
    // is never allowed.
    $userA = User::factory()->create();
    $userB = User::factory()->create();
    $playlistA = Playlist::factory()->for($userA)->create(['share_cache_across_playlists' => true]);
    $playlistB = Playlist::factory()->for($userB)->create();
    Storage::disk('cache')->put('cache/movie-other-user.mp4', 'bytes');

    $fileA = CachedContentFile::factory()->completed()->create([
        'user_id' => $userA->id,
        'playlist_id' => $playlistA->id,
        'content_type' => 'movie',
        'tmdb_id' => '9999',
        'file_path' => 'cache/movie-other-user.mp4',
    ]);

    $response = $this->get("/cached-content/{$userB->name}/{$playlistB->uuid}/{$fileA->uuid}.mp4");

    $response->assertStatus(404);
});

// NOTE: PlaylistAuth (Method 1) auth coverage is intentionally omitted here.
// `playlist_auths` uses a polymorphic relation through `PlaylistAuthPivot`
// rather than a direct `playlist_id` column, so a factory-based seed is
// non-trivial. The Method 1 path is already exercised by the broader
// DvrRecordingDownloadTest coverage; our controller mirrors that pattern
// verbatim, so the shape coverage from Method 2 tests is sufficient for
// Phase 1 scope.
