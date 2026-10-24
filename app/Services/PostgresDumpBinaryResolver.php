<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Points the backup dumper/restorer at the PostgreSQL client tools that match
 * the server's major version.
 *
 * pg_dump refuses to dump a server newer than itself, so an external Postgres
 * running a newer major than the bundled client breaks backups. The image ships
 * several client versions side by side (/usr/libexec/postgresqlNN); both
 * spatie/laravel-backup (pg_dump) and wnx/laravel-backup-restore (psql) read
 * `dump.dump_binary_path` from the connection config.
 */
class PostgresDumpBinaryResolver
{
    public function __construct(protected string $clientRoot = '/usr/libexec') {}

    /**
     * Set `dump.dump_binary_path` on a pgsql connection to the best installed
     * client for its server. Leaves an explicitly configured path untouched.
     */
    public function configure(string $connection): void
    {
        if (config("database.connections.{$connection}.driver") !== 'pgsql') {
            return;
        }

        if (filled(config("database.connections.{$connection}.dump.dump_binary_path"))) {
            return;
        }

        try {
            $serverMajorVersion = $this->serverMajorVersion($connection);
        } catch (Throwable $e) {
            Log::warning('Unable to detect PostgreSQL server version for backups, using default client tools', ['error' => $e->getMessage()]);

            return;
        }

        $binaryPath = $this->resolveBinaryPath($serverMajorVersion);
        if ($binaryPath === null) {
            Log::warning("No PostgreSQL client tools found for server version {$serverMajorVersion}, using default client tools");

            return;
        }

        config(["database.connections.{$connection}.dump.dump_binary_path" => $binaryPath]);
    }

    /**
     * Find the installed client directory for a server major version: an exact
     * match if available, otherwise the oldest newer client (pg_dump supports
     * dumping older servers, never newer ones).
     */
    public function resolveBinaryPath(int $serverMajorVersion): ?string
    {
        $installed = collect(glob("{$this->clientRoot}/postgresql[0-9]*", GLOB_ONLYDIR) ?: [])
            ->filter(fn (string $dir): bool => is_executable("{$dir}/pg_dump") && is_executable("{$dir}/psql"))
            ->mapWithKeys(fn (string $dir): array => [(int) substr(basename($dir), strlen('postgresql')) => $dir])
            ->filter(fn (string $dir, int $version): bool => $version >= $serverMajorVersion)
            ->sortKeys();

        $dir = $installed->first();

        return $dir === null ? null : $dir.'/';
    }

    public function serverMajorVersion(string $connection): int
    {
        $versionNum = (int) DB::connection($connection)->selectOne('SHOW server_version_num')->server_version_num;

        return intdiv($versionNum, 10000);
    }
}
