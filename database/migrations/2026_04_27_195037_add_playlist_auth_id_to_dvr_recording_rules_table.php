<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('dvr_recording_rules', function (Blueprint $table) {
            $table->foreignId('playlist_auth_id')
                ->nullable()
                ->after('user_id')
                ->constrained()
                ->nullOnDelete()
                ->cascadeOnUpdate();

            $table->index('playlist_auth_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dvr_recording_rules', function (Blueprint $table) {
            $table->dropForeign(['playlist_auth_id']);
            $table->dropIndex(['playlist_auth_id']);
            $table->dropColumn('playlist_auth_id');
        });
    }
};
