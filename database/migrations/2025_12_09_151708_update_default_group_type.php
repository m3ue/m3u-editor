<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('groups')
            ->whereNull('type')
            ->update(['type' => 'live']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
