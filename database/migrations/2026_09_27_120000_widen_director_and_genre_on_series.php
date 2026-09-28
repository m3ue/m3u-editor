<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * TMDB (and media server / provider) director and genre values are
     * comma-joined lists that can exceed 255 characters for long-running
     * series (e.g. every episode director), which broke metadata updates.
     */
    public function up(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->text('director')->nullable()->change();
            $table->text('genre')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('series', function (Blueprint $table) {
            $table->string('director')->nullable()->change();
            $table->string('genre')->nullable()->change();
        });
    }
};
