<?php

use App\Models\MediaServerIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

// Helpers

function makeLocalIntegration(int $userId, array $paths): MediaServerIntegration
{
    return MediaServerIntegration::create([
        'name' => 'Test Local Media',
        'type' => 'local',
        'user_id' => $userId,
        'local_media_paths' => $paths,
    ]);
}

function streamUrl(int $integrationId, string $filePath): string
{
    return route('local-media.stream', [
        'integration' => $integrationId,
        'item' => base64_encode($filePath),
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->baseDir = sys_get_temp_dir().'/local-media-test-'.uniqid();
    File::ensureDirectoryExists($this->baseDir);
});

afterEach(function () {
    File::deleteDirectory($this->baseDir);
});

// ── Regular files ─────────────────────────────────────────────────────────────

it('streams a regular file inside the configured path', function () {
    $dir = $this->baseDir.'/movies/Movie (2026)';
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/movie.mkv', 'fake-video-content');

    $integration = makeLocalIntegration($this->user->id, [
        ['path' => $this->baseDir.'/movies', 'type' => 'movies', 'name' => 'Movies'],
    ]);

    $response = $this->get(streamUrl($integration->id, $dir.'/movie.mkv'));

    $response->assertOk();
});

it('denies a regular file that is genuinely outside configured paths', function () {
    $outside = sys_get_temp_dir().'/outside-'.uniqid();
    File::ensureDirectoryExists($outside);
    file_put_contents($outside.'/secret.mkv', 'should-not-serve');

    $integration = makeLocalIntegration($this->user->id, [
        ['path' => $this->baseDir.'/movies', 'type' => 'movies', 'name' => 'Movies'],
    ]);

    $response = $this->get(streamUrl($integration->id, $outside.'/secret.mkv'));

    $response->assertForbidden();

    File::deleteDirectory($outside);
});

// ── Symlinked files (*arr + debrid/cloud-mount scenario) ─────────────────────

it('streams a symlinked file whose link is inside the configured path but target is outside', function () {
    // Simulates: /media/movies/Movie (2026)/Movie.mkv -> /mnt/decypharr/__all__/file.mkv
    $allowedDir = $this->baseDir.'/movies/Movie (2026)';
    $targetDir = $this->baseDir.'/mnt/__all__';

    File::ensureDirectoryExists($allowedDir);
    File::ensureDirectoryExists($targetDir);
    file_put_contents($targetDir.'/file.mkv', 'fake-video-content');

    $link = $allowedDir.'/Movie.mkv';
    symlink($targetDir.'/file.mkv', $link);

    $integration = makeLocalIntegration($this->user->id, [
        ['path' => $this->baseDir.'/movies', 'type' => 'movies', 'name' => 'Movies'],
    ]);

    $response = $this->get(streamUrl($integration->id, $link));

    $response->assertOk();
});

it('denies a symlinked file whose link is outside the configured path', function () {
    $outsideDir = sys_get_temp_dir().'/outside-symlinks-'.uniqid();
    $targetDir = $this->baseDir.'/mnt/__all__';

    File::ensureDirectoryExists($outsideDir);
    File::ensureDirectoryExists($targetDir);
    file_put_contents($targetDir.'/file.mkv', 'fake-video-content');

    $link = $outsideDir.'/Movie.mkv';
    symlink($targetDir.'/file.mkv', $link);

    $integration = makeLocalIntegration($this->user->id, [
        ['path' => $this->baseDir.'/movies', 'type' => 'movies', 'name' => 'Movies'],
    ]);

    $response = $this->get(streamUrl($integration->id, $link));

    $response->assertForbidden();

    File::deleteDirectory($outsideDir);
});

// ── Path traversal ────────────────────────────────────────────────────────────

it('blocks path traversal via .. in the decoded path', function () {
    $allowedDir = $this->baseDir.'/movies';
    $secretDir = $this->baseDir.'/secrets';

    File::ensureDirectoryExists($allowedDir);
    File::ensureDirectoryExists($secretDir);
    file_put_contents($secretDir.'/credentials.txt', 'super-secret');

    $integration = makeLocalIntegration($this->user->id, [
        ['path' => $allowedDir, 'type' => 'movies', 'name' => 'Movies'],
    ]);

    // The path contains ../ to escape the allowed directory
    $traversalPath = $allowedDir.'/../secrets/credentials.txt';

    $response = $this->get(streamUrl($integration->id, $traversalPath));

    $response->assertForbidden();
});

it('blocks a symlinked directory that escapes the configured path', function () {
    // If the parent *directory* is itself a symlink to outside the allowed path,
    // realpath(dirname(...)) resolves it — so the check still denies access.
    $allowedDir = $this->baseDir.'/movies';
    $outsideDir = $this->baseDir.'/outside';

    File::ensureDirectoryExists($allowedDir);
    File::ensureDirectoryExists($outsideDir);
    file_put_contents($outsideDir.'/secret.mkv', 'should-not-serve');

    // Create a symlinked directory inside the allowed path that points outside
    $symlinkDir = $allowedDir.'/escape';
    symlink($outsideDir, $symlinkDir);

    $integration = makeLocalIntegration($this->user->id, [
        ['path' => $allowedDir, 'type' => 'movies', 'name' => 'Movies'],
    ]);

    // Accessing the file via the symlinked directory — parent resolves to $outsideDir
    $response = $this->get(streamUrl($integration->id, $symlinkDir.'/secret.mkv'));

    $response->assertForbidden();
});

// ── Edge cases ────────────────────────────────────────────────────────────────

it('returns 404 for a non-existent file', function () {
    $integration = makeLocalIntegration($this->user->id, [
        ['path' => $this->baseDir.'/movies', 'type' => 'movies', 'name' => 'Movies'],
    ]);

    $response = $this->get(streamUrl($integration->id, $this->baseDir.'/movies/does-not-exist.mkv'));

    $response->assertNotFound();
});

it('returns 404 for an unknown integration', function () {
    $response = $this->get(route('local-media.stream', [
        'integration' => 99999,
        'item' => base64_encode('/some/file.mkv'),
    ]));

    $response->assertNotFound();
});

it('returns 403 for a disabled integration', function () {
    $dir = $this->baseDir.'/movies';
    File::ensureDirectoryExists($dir);
    file_put_contents($dir.'/movie.mkv', 'fake-video-content');

    $integration = makeLocalIntegration($this->user->id, [
        ['path' => $dir, 'type' => 'movies', 'name' => 'Movies'],
    ]);
    $integration->update(['enabled' => false]);

    $response = $this->get(streamUrl($integration->id, $dir.'/movie.mkv'));

    $response->assertForbidden();
});
