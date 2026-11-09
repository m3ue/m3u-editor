<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Custom groups created in the Easy Editor never got a name_internal, which is the
     * stable key bouquets select groups by. Give them one, as the API and genre groups
     * already do. Renaming a group only changes its display name, so the key stays put.
     */
    public function up(): void
    {
        DB::table('groups')
            ->where('custom', true)
            ->where(fn ($query) => $query->whereNull('name_internal')->orWhere('name_internal', ''))
            ->whereNotNull('name')
            ->where('name', '!=', '')
            ->update(['name_internal' => DB::raw('name')]);
    }

    /**
     * The backfilled keys can't be told apart from ones set on creation, so they stay.
     */
    public function down(): void
    {
        //
    }
};
