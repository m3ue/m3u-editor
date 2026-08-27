<?php

namespace App\Filament\Widgets\Concerns;

use Illuminate\Support\Facades\DB;

trait FormatsDateColumn
{
    /**
     * A driver-portable SQL expression that formats a timestamp column to a
     * "YYYY-MM-DD" text value (never a date type, so it stays safe to mix with
     * text sentinels in a CASE - Postgres would otherwise try to coerce the
     * sentinel to a date and fail).
     *
     * The column name is a hardcoded caller literal and the returned string is
     * one of three compile-time SQL literals chosen by the PDO driver name; no
     * request or user input is interpolated.
     */
    protected function dateExpr(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'pgsql' => "to_char({$column}, 'YYYY-MM-DD')",
            'sqlite' => "strftime('%Y-%m-%d', {$column})",
            default => "DATE_FORMAT({$column}, '%Y-%m-%d')",
        };
    }
}
