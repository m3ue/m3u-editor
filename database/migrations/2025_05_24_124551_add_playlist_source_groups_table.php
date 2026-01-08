<?php

use App\Models\SourceGroup;
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
        Schema::disableForeignKeyConstraints();

        Schema::create('source_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->timestamps();
            $table->unique(['name', 'playlist_id']);
        });

        Schema::enableForeignKeyConstraints();

        // Migrate existing playlist groups to the new table
        DB::table('playlists')->whereNotNull('groups')->get()->each(function ($playlist) {
            $inserts = [];
            foreach (json_decode($playlist->groups, true) as $group) {
                $inserts[] = [
                    'name' => $group,
                    'playlist_id' => $playlist->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            $inserts = collect($inserts)
                ->unique(function ($item) {
                    return $item['name'].$item['playlist_id'];
                })->toArray();
            SourceGroup::upsert($inserts, uniqueBy: ['name', 'playlist_id'], update: []);
        });

        // Leave in case we need to revert at some point...
        // // Remove the old groups column from playlists
        // Schema::table('playlists', function (Blueprint $table) {
        //     $table->dropColumn('groups');
        // });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('source_groups');
    }
};
