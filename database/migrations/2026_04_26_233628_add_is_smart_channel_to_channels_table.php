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
        Schema::table('channels', function (Blueprint $table) {
            $table->boolean('is_smart_channel')->default(false)->after('is_custom');
            $table->index('is_smart_channel');
        });

        // Backfill: any existing custom channel with no URL of its own that
        // already has at least one failover attached is, in effect, a smart
        // channel — flip the flag so the UI/scoping treats it as one.
        DB::table('channels')
            ->where('is_custom', true)
            ->whereNull('url')
            ->whereExists(function ($query) {
                $query->select(DB::raw(1))
                    ->from('channel_failovers')
                    ->whereColumn('channel_failovers.channel_id', 'channels.id');
            })
            ->update(['is_smart_channel' => true]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropIndex(['is_smart_channel']);
            $table->dropColumn('is_smart_channel');
        });
    }
};
