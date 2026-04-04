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
        // channels.group_id: used in WHERE clauses in XtreamApiController,
        // ChannelController, ListChannels, UnmergeChannels - not covered by
        // the composite indexes in add_performance_indexes migration.
        Schema::table('channels', function (Blueprint $table) {
            $table->index('group_id', 'idx_channels_group_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropIndex('idx_channels_group_id');
        });
    }
};
