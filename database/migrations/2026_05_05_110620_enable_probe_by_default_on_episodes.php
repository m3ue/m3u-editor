<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Backfill: enable probing for all existing episodes to match channel behaviour.
        DB::table('episodes')->where('probe_enabled', false)->update(['probe_enabled' => true]);

        Schema::table('episodes', function (Blueprint $table) {
            $table->boolean('probe_enabled')->default(true)->change();
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->boolean('probe_enabled')->default(false)->change();
        });
    }
};
