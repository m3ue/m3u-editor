<?php

namespace App\Listeners;

use Illuminate\Support\Facades\DB;
use Wnx\LaravelBackupRestore\Events\DatabaseReset;

/**
 * `backup:restore --reset` only drops tables, so SQL functions created by
 * migrations (e.g. `m3u_natural_sort_key`) survive the reset. A plain pg_dump
 * recreates them with `CREATE FUNCTION`, and since psql runs the import with
 * `ON_ERROR_STOP=1` the resulting "already exists" error aborts the restore.
 * Drop the app's own functions once the tables are gone so the dump can
 * recreate them. Runs synchronously: it must finish before the import starts.
 */
class DropPostgresFunctionsAfterBackupReset
{
    public function handle(DatabaseReset $event): void
    {
        $connection = DB::connection($event->pendingRestore->connection);

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        // Functions in the current schema, excluding ones owned by an
        // extension (those are dropped and recreated with the extension).
        $signatures = $connection->table('pg_proc as p')
            ->join('pg_namespace as n', 'n.oid', '=', 'p.pronamespace')
            ->leftJoin('pg_depend as d', function ($join): void {
                $join->on('d.objid', '=', 'p.oid')->where('d.deptype', 'e');
            })
            ->whereRaw('n.nspname = current_schema()')
            ->whereNull('d.objid')
            ->selectRaw('p.oid::regprocedure::text as signature')
            ->pluck('signature');

        foreach ($signatures as $signature) {
            $connection->statement("DROP FUNCTION IF EXISTS {$signature} CASCADE");
        }
    }
}
