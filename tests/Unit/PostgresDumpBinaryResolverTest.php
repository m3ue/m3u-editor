<?php

use App\Services\PostgresDumpBinaryResolver;
use Illuminate\Support\Facades\File;

function fakePostgresClient(string $root, int $version, bool $withPsql = true): void
{
    $dir = "{$root}/postgresql{$version}";
    File::ensureDirectoryExists($dir);

    foreach ($withPsql ? ['pg_dump', 'psql'] : ['pg_dump'] as $binary) {
        File::put("{$dir}/{$binary}", "#!/bin/sh\n");
        chmod("{$dir}/{$binary}", 0755);
    }
}

beforeEach(function () {
    $this->clientRoot = sys_get_temp_dir().'/pg-clients-'.uniqid();
    File::ensureDirectoryExists($this->clientRoot);

    config([
        'database.pg_client_version' => null,
        'database.connections.pgsql_backup' => [
            'driver' => 'pgsql',
            'database' => 'm3ue',
        ],
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->clientRoot);
});

it('prefers the client matching the requested version', function () {
    fakePostgresClient($this->clientRoot, 17);
    fakePostgresClient($this->clientRoot, 18);

    $resolver = new PostgresDumpBinaryResolver($this->clientRoot);

    expect($resolver->resolveBinaryPath(17))->toBe("{$this->clientRoot}/postgresql17/")
        ->and($resolver->resolveBinaryPath(18))->toBe("{$this->clientRoot}/postgresql18/");
});

it('falls back to the oldest newer client when no exact match is installed', function () {
    fakePostgresClient($this->clientRoot, 17);
    fakePostgresClient($this->clientRoot, 18);

    $resolver = new PostgresDumpBinaryResolver($this->clientRoot);

    expect($resolver->resolveBinaryPath(16))->toBe("{$this->clientRoot}/postgresql17/")
        ->and($resolver->resolveBinaryPath(19))->toBeNull();
});

it('ignores client directories missing pg_dump or psql', function () {
    fakePostgresClient($this->clientRoot, 18, withPsql: false);
    File::ensureDirectoryExists("{$this->clientRoot}/postgresql-common");

    expect((new PostgresDumpBinaryResolver($this->clientRoot))->resolveBinaryPath(18))->toBeNull();
});

it('sets the dump binary path for the requested PG_CLIENT_VERSION', function () {
    fakePostgresClient($this->clientRoot, 17);
    fakePostgresClient($this->clientRoot, 18);
    config(['database.pg_client_version' => 18]);

    (new PostgresDumpBinaryResolver($this->clientRoot))->configure('pgsql_backup');

    expect(config('database.connections.pgsql_backup.dump.dump_binary_path'))
        ->toBe("{$this->clientRoot}/postgresql18/");
});

it('throws when no installed client satisfies PG_CLIENT_VERSION', function () {
    fakePostgresClient($this->clientRoot, 17);
    config(['database.pg_client_version' => 99]);

    (new PostgresDumpBinaryResolver($this->clientRoot))->configure('pgsql_backup');
})->throws(RuntimeException::class, 'No PostgreSQL client tools installed for PG_CLIENT_VERSION=99');

it('leaves the default client in place when PG_CLIENT_VERSION is unset', function () {
    fakePostgresClient($this->clientRoot, 18);

    (new PostgresDumpBinaryResolver($this->clientRoot))->configure('pgsql_backup');

    expect(config('database.connections.pgsql_backup.dump.dump_binary_path'))->toBeNull();
});

it('does nothing for non-postgres connections', function () {
    config(['database.pg_client_version' => 18]);

    (new PostgresDumpBinaryResolver($this->clientRoot))->configure('sqlite_testing');

    expect(config('database.connections.sqlite_testing.dump.dump_binary_path'))->toBeNull();
});
