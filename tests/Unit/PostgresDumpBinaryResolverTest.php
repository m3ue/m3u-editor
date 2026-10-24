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
        'database.connections.pgsql_backup' => [
            'driver' => 'pgsql',
            'database' => 'm3ue',
        ],
    ]);
});

afterEach(function () {
    File::deleteDirectory($this->clientRoot);
});

it('prefers the client matching the server major version', function () {
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

it('sets the dump binary path for the server version', function () {
    fakePostgresClient($this->clientRoot, 17);
    fakePostgresClient($this->clientRoot, 18);

    $resolver = Mockery::mock(PostgresDumpBinaryResolver::class, [$this->clientRoot])->makePartial();
    $resolver->shouldReceive('serverMajorVersion')->with('pgsql_backup')->andReturn(18);

    $resolver->configure('pgsql_backup');

    expect(config('database.connections.pgsql_backup.dump.dump_binary_path'))
        ->toBe("{$this->clientRoot}/postgresql18/");
});

it('keeps an explicitly configured dump binary path', function () {
    fakePostgresClient($this->clientRoot, 18);
    config(['database.connections.pgsql_backup.dump.dump_binary_path' => '/opt/pg/bin/']);

    $resolver = Mockery::mock(PostgresDumpBinaryResolver::class, [$this->clientRoot])->makePartial();
    $resolver->shouldNotReceive('serverMajorVersion');

    $resolver->configure('pgsql_backup');

    expect(config('database.connections.pgsql_backup.dump.dump_binary_path'))->toBe('/opt/pg/bin/');
});

it('leaves the default client in place when the server version cannot be read', function () {
    fakePostgresClient($this->clientRoot, 18);

    $resolver = Mockery::mock(PostgresDumpBinaryResolver::class, [$this->clientRoot])->makePartial();
    $resolver->shouldReceive('serverMajorVersion')->andThrow(new RuntimeException('connection refused'));

    $resolver->configure('pgsql_backup');

    expect(config('database.connections.pgsql_backup.dump.dump_binary_path'))->toBeNull();
});

it('does nothing for non-postgres connections', function () {
    $resolver = Mockery::mock(PostgresDumpBinaryResolver::class, [$this->clientRoot])->makePartial();
    $resolver->shouldNotReceive('serverMajorVersion');

    $resolver->configure('sqlite_testing');

    expect(config('database.connections.sqlite_testing.dump.dump_binary_path'))->toBeNull();
});
