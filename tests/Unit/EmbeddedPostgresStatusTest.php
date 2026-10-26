<?php

declare(strict_types=1);

use App\Services\EmbeddedPostgresStatus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    Cache::flush();
    $this->mount = storage_path('framework/testing/pg-mount-'.uniqid());
    File::ensureDirectoryExists($this->mount);
    config(['database.embedded_postgres_status_path' => "{$this->mount}/.m3ue-postgres-status.json"]);
});

afterEach(function () {
    File::deleteDirectory($this->mount);
});

function writePostgresStatus(string $mount, array $status): void
{
    File::put("{$mount}/.m3ue-postgres-status.json", json_encode($status));
}

/**
 * Minimal stand-in for a Postgres root cluster: just enough files for
 * EmbeddedPostgresStatus's allowlist-based delete to exercise both branches
 * (directories and plain files) and for size summation to have something to sum.
 */
function fakeRootCluster(string $mount, string $version = '17'): void
{
    File::put("{$mount}/PG_VERSION", $version);
    File::put("{$mount}/postgresql.conf", '# conf');
    File::ensureDirectoryExists("{$mount}/base");
    File::put("{$mount}/base/1", str_repeat('x', 1024));
}

function fakeNestedCluster(string $mount, string $version): void
{
    File::ensureDirectoryExists("{$mount}/pg{$version}/base");
    File::put("{$mount}/pg{$version}/PG_VERSION", $version);
    File::put("{$mount}/pg{$version}/base/1", str_repeat('y', 2048));
}

it('returns null when the status file does not exist', function () {
    expect((new EmbeddedPostgresStatus)->read())->toBeNull();
});

it('returns null when the status file is not valid JSON', function () {
    File::put((new EmbeddedPostgresStatus)->statusPath(), 'not json');

    expect((new EmbeddedPostgresStatus)->read())->toBeNull();
});

it('decodes a valid status file', function () {
    writePostgresStatus($this->mount, ['active_version' => '18', 'active_path' => "{$this->mount}/pg18"]);

    expect((new EmbeddedPostgresStatus)->read())->toMatchArray(['active_version' => '18']);
});

it('reports no retained copies when nothing has ever been upgraded', function () {
    fakeRootCluster($this->mount);
    writePostgresStatus($this->mount, ['active_version' => '17', 'active_path' => $this->mount]);

    expect((new EmbeddedPostgresStatus)->retainedCopies())->toBe([]);
});

it('finds the pre-upgrade root cluster as a retained copy once active has moved to a nested cluster', function () {
    fakeRootCluster($this->mount, '17');
    fakeNestedCluster($this->mount, '18');
    writePostgresStatus($this->mount, ['active_version' => '18', 'active_path' => "{$this->mount}/pg18"]);

    $copies = (new EmbeddedPostgresStatus)->retainedCopies();

    expect($copies)->toHaveCount(1)
        ->and($copies[0]['kind'])->toBe('previous_cluster')
        ->and($copies[0]['version'])->toBe('17')
        ->and($copies[0]['path'])->toBe($this->mount)
        // Only the root's own files (PG_VERSION, postgresql.conf, base/1), not pg18/base/1.
        ->and($copies[0]['bytes'])->toBe(strlen('17') + strlen('# conf') + 1024);
});

it('finds rolled-back nested clusters by directory name', function () {
    File::ensureDirectoryExists("{$this->mount}/pg18.rolled-back-20260101120000/base");
    File::put("{$this->mount}/pg18.rolled-back-20260101120000/base/1", str_repeat('z', 512));
    writePostgresStatus($this->mount, ['active_version' => '17', 'active_path' => $this->mount]);

    $copies = (new EmbeddedPostgresStatus)->retainedCopies();

    expect($copies)->toHaveCount(1)
        ->and($copies[0]['kind'])->toBe('rolled_back')
        ->and($copies[0]['version'])->toBe('18')
        ->and($copies[0]['created_at'])->toBe('2026-01-01 12:00:00')
        ->and($copies[0]['bytes'])->toBe(512);
});

it('deletes the retained root cluster without touching the active nested cluster', function () {
    fakeRootCluster($this->mount, '17');
    fakeNestedCluster($this->mount, '18');
    writePostgresStatus($this->mount, ['active_version' => '18', 'active_path' => "{$this->mount}/pg18"]);

    (new EmbeddedPostgresStatus)->deleteRetainedCopies();

    expect(File::exists("{$this->mount}/PG_VERSION"))->toBeFalse()
        ->and(File::exists("{$this->mount}/postgresql.conf"))->toBeFalse()
        ->and(File::isDirectory("{$this->mount}/base"))->toBeFalse()
        // The active cluster and the status file itself are never in the allowlist.
        ->and(File::exists("{$this->mount}/pg18/PG_VERSION"))->toBeTrue()
        ->and(File::exists((new EmbeddedPostgresStatus)->statusPath()))->toBeTrue()
        ->and((new EmbeddedPostgresStatus)->retainedCopies())->toBe([]);
});

it('deletes a rolled-back cluster directory entirely', function () {
    File::ensureDirectoryExists("{$this->mount}/pg18.rolled-back-20260101120000/base");
    writePostgresStatus($this->mount, ['active_version' => '17', 'active_path' => $this->mount]);

    (new EmbeddedPostgresStatus)->deleteRetainedCopies();

    expect(File::isDirectory("{$this->mount}/pg18.rolled-back-20260101120000"))->toBeFalse();
});

it('never deletes an unrecognized file at the mount root', function () {
    fakeRootCluster($this->mount, '17');
    fakeNestedCluster($this->mount, '18');
    File::put("{$this->mount}/.m3ue-cluster-complete-notes.txt", 'do not delete me');
    writePostgresStatus($this->mount, ['active_version' => '18', 'active_path' => "{$this->mount}/pg18"]);

    (new EmbeddedPostgresStatus)->deleteRetainedCopies();

    expect(File::exists("{$this->mount}/.m3ue-cluster-complete-notes.txt"))->toBeTrue();
});

it('caches the retained copy size instead of re-walking the directory on every call', function () {
    fakeRootCluster($this->mount, '17');
    fakeNestedCluster($this->mount, '18');
    writePostgresStatus($this->mount, ['active_version' => '18', 'active_path' => "{$this->mount}/pg18"]);

    $status = new EmbeddedPostgresStatus;
    $firstSize = $status->retainedCopies()[0]['bytes'];

    // Grow the root cluster without clearing the cache - a cache hit would
    // still report the old (smaller) size.
    File::put("{$this->mount}/base/2", str_repeat('x', 5000));

    expect($status->retainedCopies()[0]['bytes'])->toBe($firstSize);
});

it('recomputes the size after the cached copy is deleted and a new one appears at the same path', function () {
    fakeRootCluster($this->mount, '17');
    fakeNestedCluster($this->mount, '18');
    writePostgresStatus($this->mount, ['active_version' => '18', 'active_path' => "{$this->mount}/pg18"]);

    $status = new EmbeddedPostgresStatus;
    $firstSize = $status->retainedCopies()[0]['bytes'];

    $status->deleteRetainedCopies();

    // A later upgrade retains a new, differently-sized copy at the same root path.
    fakeRootCluster($this->mount, '18');
    File::put("{$this->mount}/base/2", str_repeat('x', 5000));
    fakeNestedCluster($this->mount, '19');
    writePostgresStatus($this->mount, ['active_version' => '19', 'active_path' => "{$this->mount}/pg19"]);

    expect($status->retainedCopies()[0]['bytes'])->toBeGreaterThan($firstSize);
});
