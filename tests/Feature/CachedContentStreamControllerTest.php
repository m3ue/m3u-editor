<?php

use App\Models\CachedContentFile;
use App\Models\DynamicGroup;
use App\Models\Playlist;
use App\Models\PlaylistAuth;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;

/**
 * Regression coverage for the new Dynamic Group Cache streaming endpoint
 * (`dynamic-group-cache.stream`):
 *  - Auth mirrors the Xtream/DVR stream routes (PlaylistAuth credentials or
 *    playlist UUID + owner name).
 *  - Ownership is via DynamicGroup-membership, since `cached_content_files`
 *    has no `user_id` column (per Phase 1's design — one cached file is
 *    referenced by multiple playlists/groups).
 *  - HTTP Range requests get a 206 partial response with correct
 *    Content-Range header.
 *  - 404 when the file's status isn't Completed or the file is missing on
 *    disk.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    // Playlist::factory()->create() fires PlaylistCreated → SyncPipelineService
    // → dispatch(ProcessM3uImport). Bus::fake() catches that; the stream
    // controller itself never dispatches jobs.
    Bus::fake();
    Storage::fake('local');

    $this->user = User::factory()->create();
    $this->playlist = Playlist::factory()->for($this->user)->create();

    $this->auth = PlaylistAuth::create([
        'name' => 'Test Credential',
        'username' => 'test-credential',
        'password' => 'test-password',
        'enabled' => true,
        'user_id' => $this->user->id,
    ]);
    $this->playlist->playlistAuths()->attach($this->auth->id);

    $this->group = DynamicGroup::create([
        'playlist_id' => $this->playlist->id,
        'user_id' => $this->user->id,
        'type' => 'vod',
        'source' => 'tmdb',
        'name' => 'Top Movies',
    ]);

    // Completed file with a known body for Range tests
    $this->fileBody = str_repeat('X', 1024); // 1 KiB
    $this->file = CachedContentFile::factory()->completed()->create([
        'content_type' => 'movie',
        'tmdb_id' => '550',
        'quality' => null,
    ]);
    Storage::disk('local')->put($this->file->file_path, $this->fileBody);
});

/**
 * Mirror `DvrStreamControllerTest::dvrStreamUrl()` — the {format?} segment is
 * optional and Laravel's `route()` helper generates a trailing-dot URL when
 * omitted, which the route itself won't match.
 */
function cachedStreamUrl(string $username, string $password, string $uuid, string $format = 'ts'): string
{
    return route('dynamic-group-cache.stream', [
        'username' => $username,
        'password' => $password,
        'uuid' => $uuid,
        'format' => $format,
    ]);
}

it('streams a Completed cache file with a 200 full response when no Range header', function () {
    $this->file->dynamicGroups()->attach($this->group->id);

    $response = $this->get(cachedStreamUrl($this->user->name, $this->playlist->uuid, $this->file->uuid));

    $response->assertOk()
        ->assertHeader('Content-Length', (string) strlen($this->fileBody))
        ->assertHeader('Accept-Ranges', 'bytes')
        ->assertHeader('Content-Type', $this->file->resolveMimeType());

    expect($response->streamedContent())->toBe($this->fileBody);
});

it('streams a Completed cache file with a 206 partial response when Range header is set', function () {
    $this->file->dynamicGroups()->attach($this->group->id);

    // Request the first 100 bytes
    $response = $this->get(cachedStreamUrl($this->user->name, $this->playlist->uuid, $this->file->uuid), [
        'Range' => 'bytes=0-99',
    ]);

    $response->assertStatus(206)
        ->assertHeader('Content-Length', '100')
        ->assertHeader('Content-Range', 'bytes 0-99/'.strlen($this->fileBody))
        ->assertHeader('Accept-Ranges', 'bytes');

    $body = $response->streamedContent();
    expect($body)->toBe(substr($this->fileBody, 0, 100));
});

it('streams a 206 partial from an arbitrary offset to end', function () {
    $this->file->dynamicGroups()->attach($this->group->id);

    // Request bytes 500-700
    $response = $this->get(cachedStreamUrl($this->user->name, $this->playlist->uuid, $this->file->uuid), [
        'Range' => 'bytes=500-700',
    ]);

    $response->assertStatus(206)
        ->assertHeader('Content-Length', '201')
        ->assertHeader('Content-Range', 'bytes 500-700/'.strlen($this->fileBody));

    $body = $response->streamedContent();
    expect($body)->toBe(substr($this->fileBody, 500, 201));
});

it('returns 404 when the file is not referenced by any group of the authenticated playlist (no existence leak)', function () {
    // The file exists and is Completed, but is NOT attached to any group on
    // this playlist — a different playlist owns it. The controller folds the
    // ownership check into the whereHas query so "exists but not yours" and
    // "doesn't exist" both 404 — callers can't probe uuid ownership by status.
    $otherUser = User::factory()->create();
    $otherPlaylist = Playlist::factory()->for($otherUser)->create();

    $forbidden = $this->get(cachedStreamUrl($this->user->name, $this->playlist->uuid, $this->file->uuid));
    $missing = $this->get(cachedStreamUrl($this->user->name, $this->playlist->uuid, '00000000-0000-0000-0000-000000000000'));

    expect($forbidden->getStatusCode())->toBe($missing->getStatusCode())->toBe(404);
});

it('returns 404 when the file has no file_path (e.g. status is Pending, Downloading, or Failed)', function () {
    $pendingFile = CachedContentFile::factory()->create([
        'content_type' => 'movie',
        'tmdb_id' => '551',
        'status' => 'pending',
    ]);
    $pendingFile->dynamicGroups()->attach($this->group->id);

    $response = $this->get(cachedStreamUrl($this->user->name, $this->playlist->uuid, $pendingFile->uuid));

    $response->assertNotFound();
});

it('returns 404 when the file row exists but the disk file is missing', function () {
    $this->file->dynamicGroups()->attach($this->group->id);
    Storage::disk('local')->delete($this->file->file_path);

    $response = $this->get(cachedStreamUrl($this->user->name, $this->playlist->uuid, $this->file->uuid));

    $response->assertNotFound();
});

it('returns 401 when credentials do not resolve', function () {
    $this->file->dynamicGroups()->attach($this->group->id);

    $response = $this->get(cachedStreamUrl('wrong-user', $this->playlist->uuid, $this->file->uuid));

    $response->assertStatus(401);
});

it('authenticates with PlaylistAuth credentials too', function () {
    $this->file->dynamicGroups()->attach($this->group->id);

    $response = $this->get(cachedStreamUrl('test-credential', 'test-password', $this->file->uuid));

    $response->assertOk();
});

it('returns 404 when the file is referenced only by a group of a different playlist', function () {
    // Attach to a group belonging to a different playlist
    $otherUser = User::factory()->create();
    $otherPlaylist = Playlist::factory()->for($otherUser)->create();
    $otherGroup = DynamicGroup::create([
        'playlist_id' => $otherPlaylist->id,
        'user_id' => $otherUser->id,
        'type' => 'vod',
        'source' => 'tmdb',
        'name' => 'Other Top Movies',
    ]);
    $this->file->dynamicGroups()->attach($otherGroup->id);

    $forbidden = $this->get(cachedStreamUrl($this->user->name, $this->playlist->uuid, $this->file->uuid));
    $missing = $this->get(cachedStreamUrl($this->user->name, $this->playlist->uuid, '00000000-0000-0000-0000-000000000000'));

    expect($forbidden->getStatusCode())->toBe($missing->getStatusCode())->toBe(404);
});
