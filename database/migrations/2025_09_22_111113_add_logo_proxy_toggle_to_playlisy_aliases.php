<?php

use App\Models\PlaylistAlias;
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
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->boolean('enable_logo_proxy')
                ->default(false)
                ->after('enable_proxy');
        });

        // Need to update the existing aliases to have this enabled when they have proxy enabled
        $playlist_aliases = PlaylistAlias::where('enable_proxy', true);
        foreach ($playlist_aliases->cursor() as $playlist) {
            $playlist->enable_logo_proxy = true;
            $playlist->save();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('playlist_aliases', function (Blueprint $table) {
            $table->dropColumn('enable_logo_proxy');
        });
    }
};
