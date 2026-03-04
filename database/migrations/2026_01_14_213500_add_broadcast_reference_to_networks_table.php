<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            // Add columns if they do not already exist (guard against partial runs)
            if (! Schema::hasColumn('networks', 'broadcast_programme_id')) {
                $table->unsignedBigInteger('broadcast_programme_id')->nullable();
            }

            if (! Schema::hasColumn('networks', 'broadcast_initial_offset_seconds')) {
                $table->integer('broadcast_initial_offset_seconds')->nullable();
            }

            // Foreign key already managed or created previously; skip adding here to avoid duplicate constraint errors.
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            // Drop foreign if exists, then drop columns if present
            try {
                $table->dropForeign(['broadcast_programme_id']);
            } catch (\Throwable $e) {
                // ignore missing foreign
            }

            if (Schema::hasColumn('networks', 'broadcast_programme_id')) {
                $table->dropColumn('broadcast_programme_id');
            }

            if (Schema::hasColumn('networks', 'broadcast_initial_offset_seconds')) {
                $table->dropColumn('broadcast_initial_offset_seconds');
            }
        });
    }
};
