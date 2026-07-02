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
        Schema::table('groups', function (Blueprint $table) {
            $table->unsignedBigInteger('source_group_id')->nullable()->after('name_internal');
            $table->index(['source_group_id', 'playlist_id', 'type'], 'groups_source_group_id_playlist_id_type_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->dropIndex('groups_source_group_id_playlist_id_type_index');
            $table->dropColumn('source_group_id');
        });
    }
};
