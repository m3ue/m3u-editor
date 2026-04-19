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
        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE channels ALTER COLUMN tmdb_id TYPE INTEGER USING tmdb_id::INTEGER');
            DB::statement('ALTER TABLE series ALTER COLUMN tmdb_id TYPE INTEGER USING tmdb_id::INTEGER');
        } else {
            Schema::table('channels', function (Blueprint $table) {
                $table->integer('tmdb_id')->nullable()->change();
            });

            Schema::table('series', function (Blueprint $table) {
                $table->integer('tmdb_id')->nullable()->change();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('tmdb_id')->nullable()->change();
        });

        Schema::table('series', function (Blueprint $table) {
            $table->string('tmdb_id')->nullable()->change();
        });
    }
};
