<?php

namespace App\Services;

use RuntimeException;

/**
 * Points the backup dumper/restorer at the PostgreSQL client tools matching
 * the `PG_CLIENT_VERSION` env var.
 *
 * pg_dump refuses to dump a server newer than itself, so an external Postgres
 * running a newer major than the bundled client breaks backups. The image
 * ships several client versions side by side (/usr/libexec/postgresqlNN);
 * both spatie/laravel-backup (pg_dump) and wnx/laravel-backup-restore (psql)
 * read `dump.dump_binary_path` from the connection config. The server version
 * is never auto-detected: PG_CLIENT_VERSION must be set explicitly when the
 * external server's major differs from the bundled default (17).
 */
class PostgresDumpBinaryResolver
{
    public function __construct(protected string $clientRoot = '/usr/libexec') {}

    /**
     * Set `dump.dump_binary_path` on a pgsql connection to the client tools
     * requested via `PG_CLIENT_VERSION`. Does nothing when that env var is
     * unset, leaving the bundled default (17) client on PATH in place.
     */
    public function configure(string $connection): void
    {
        if (config("database.connections.{$connection}.driver") !== 'pgsql') {
            return;
        }

        $clientVersion = config('database.pg_client_version');
        if (blank($clientVersion)) {
            return;
        }

        $binaryPath = $this->resolveBinaryPath((int) $clientVersion);
        if ($binaryPath === null) {
            throw new RuntimeException("No PostgreSQL client tools installed for PG_CLIENT_VERSION={$clientVersion}");
        }

        config(["database.connections.{$connection}.dump.dump_binary_path" => $binaryPath]);
    }

    /**
     * Find the installed client directory for a major version: an exact
     * match if available, otherwise the oldest newer client (pg_dump supports
     * dumping older servers, never newer ones).
     */
    public function resolveBinaryPath(int $clientVersion): ?string
    {
        $installed = collect(glob("{$this->clientRoot}/postgresql[0-9]*", GLOB_ONLYDIR) ?: [])
            ->filter(fn (string $dir): bool => is_executable("{$dir}/pg_dump") && is_executable("{$dir}/psql"))
            ->mapWithKeys(fn (string $dir): array => [(int) substr(basename($dir), strlen('postgresql')) => $dir])
            ->filter(fn (string $dir, int $version): bool => $version >= $clientVersion)
            ->sortKeys();

        $dir = $installed->first();

        return $dir === null ? null : $dir.'/';
    }
}
