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
        Schema::table('epgs', function (Blueprint $table) {
            $table->boolean('preprocess')->default(false)->after('is_merged');
            $table->foreignId('preprocess_playlist_id')
                ->nullable()
                ->after('preprocess')
                ->constrained('playlists')
                ->nullOnDelete();
            $table->boolean('preprocess_display_name_filter')->default(false)->after('preprocess_playlist_id');
            $table->json('preprocess_display_name_prefixes')->nullable()->after('preprocess_display_name_filter');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epgs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preprocess_playlist_id');
            $table->dropColumn([
                'preprocess',
                'preprocess_display_name_filter',
                'preprocess_display_name_prefixes',
            ]);
        });
    }
};
