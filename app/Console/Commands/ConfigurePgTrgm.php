<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ConfigurePgTrgm extends Command
{
    private const CONNECTION_NAME = 'pg_trgm_setup';

    protected $signature = 'app:configure-pg-trgm
        {--host= : Defaults to the app\'s configured pgsql connection}
        {--port= : Defaults to the app\'s configured pgsql connection}
        {--database= : Defaults to the app\'s configured pgsql connection}
        {--username= : Superuser or database-owner role. Defaults to the app\'s configured pgsql connection}
        {--password= : Prompted if --username is given without it}
        {--threshold= : pg_trgm similarity threshold. Defaults to TRGM_THRESHOLD or 0.35}';

    protected $description = 'Install pg_trgm and its EPG candidate-recall indexes on an external Postgres database (the embedded Postgres image already does this via docker/8.4/db-init.sh)';

    public function handle(): int
    {
        if (config('database.default') !== 'pgsql' && ! $this->option('host')) {
            $this->components->warn('The app is not configured for a pgsql connection and no --host was given - pg_trgm only applies to Postgres.');
        }

        $connectionConfig = array_merge(config('database.connections.pgsql'), array_filter([
            'host' => $this->option('host'),
            'port' => $this->option('port'),
            'database' => $this->option('database'),
            'username' => $this->option('username'),
        ]));

        // Only prompt for a password if the caller is actually supplying
        // different credentials - if nothing was overridden, the app's own
        // configured connection (and its stored password) is what's used.
        if ($this->option('username') || $this->option('password')) {
            $connectionConfig['password'] = $this->option('password') ?: $this->secret('Password for '.$connectionConfig['username']);
        }

        $threshold = $this->option('threshold') ?: env('TRGM_THRESHOLD', 0.35);

        config(['database.connections.'.self::CONNECTION_NAME => $connectionConfig]);
        $connection = DB::connection(self::CONNECTION_NAME);

        try {
            $connection->getPdo();
        } catch (\Throwable $e) {
            $this->components->error("Could not connect to {$connectionConfig['host']}:{$connectionConfig['port']}/{$connectionConfig['database']} as {$connectionConfig['username']}: {$e->getMessage()}");

            return Command::FAILURE;
        }

        $this->components->info("Connected to {$connectionConfig['database']}@{$connectionConfig['host']} as {$connectionConfig['username']}.");

        $this->components->task('Installing pg_trgm and fuzzystrmatch extensions', function () use ($connection) {
            $connection->statement('CREATE EXTENSION IF NOT EXISTS fuzzystrmatch');
            $connection->statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');
        });

        try {
            $this->components->task("Setting pg_trgm.similarity_threshold = {$threshold}", function () use ($connection, $connectionConfig, $threshold) {
                $connection->statement("ALTER DATABASE \"{$connectionConfig['database']}\" SET pg_trgm.similarity_threshold = {$threshold}");
            });
        } catch (QueryException $e) {
            // SQLSTATE 42501 (insufficient_privilege) - ALTER DATABASE ... SET
            // for an extension-defined GUC requires superuser or an explicit
            // GRANT SET ON PARAMETER, even for the database owner. Extension
            // install above still succeeded, so don't fail the whole command
            // - just tell the user their role can't set the database-wide
            // default and let them decide whether that matters.
            if ($e->getCode() !== '42501') {
                throw $e;
            }

            $this->components->warn("The '{$connectionConfig['username']}' role can't set the database-wide pg_trgm.similarity_threshold default (needs superuser or GRANT SET ON PARAMETER). SimilaritySearchService still works - it uses the connection default or Postgres's built-in default (0.3) instead of {$threshold}. Re-run with superuser credentials to set the tuned default.");
        }

        if (! Schema::connection(self::CONNECTION_NAME)->hasTable('epg_channels')) {
            $this->components->warn('epg_channels does not exist yet - run `php artisan migrate` first, then re-run this command to build the trigram indexes.');

            return Command::SUCCESS;
        }

        // CONCURRENTLY avoids taking an exclusive lock on epg_channels while
        // it builds - a plain CREATE INDEX would block any in-flight EPG
        // import job writing to that table for the duration of the build.
        // It requires running outside a transaction block, which a bare
        // DB::statement() call already does by default (no explicit
        // DB::transaction()/beginTransaction() wraps this).
        foreach ([
            'idx_epg_channels_channel_id_trgm' => 'LOWER(channel_id)',
            'idx_epg_channels_name_trgm' => 'LOWER(name)',
            'idx_epg_channels_display_name_trgm' => 'LOWER(display_name)',
        ] as $index => $expression) {
            $this->components->task("Building {$index}", function () use ($connection, $index, $expression) {
                $connection->statement("CREATE INDEX CONCURRENTLY IF NOT EXISTS {$index} ON epg_channels USING gin ({$expression} gin_trgm_ops)");
            });
        }

        $this->components->task('Refreshing epg_channels statistics', function () use ($connection) {
            $connection->statement('ANALYZE epg_channels');
        });

        $this->components->info('pg_trgm is configured. EPG matching will automatically widen candidate recall using it from here on - no restart needed.');

        return Command::SUCCESS;
    }
}
