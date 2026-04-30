<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('stream_profiles', function (Blueprint $table) {
            $table->renameColumn('cookies', 'cookies_path');
        });

        // Existing values were cookie file contents (not paths) — clear them.
        DB::table('stream_profiles')->whereNotNull('cookies_path')->update(['cookies_path' => null]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stream_profiles', function (Blueprint $table) {
            $table->renameColumn('cookies_path', 'cookies');
        });
    }
};
