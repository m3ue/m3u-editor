<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private $oldUniqueColumns = ['name', 'channel_id', 'epg_id', 'user_id'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('epg_channels', function (Blueprint $table) {
            $table->string('source_id', 32)->nullable()->after('channel_id');
        });

        // Backfill source_id using the same collision-relative hashing used in the import job.
        // Within each (epg_id, user_id) scope, channels are ordered by id so the first
        // occurrence of a channel_id gets the base md5 and subsequent duplicates get :dup:N
        // suffixes — matching exactly what the import job produces going forward.
        $seen = [];
        DB::table('epg_channels')
            ->whereNotNull('channel_id')
            ->orderBy('id')
            ->chunkById(500, function ($rows) use (&$seen) {
                foreach ($rows as $row) {
                    $key = $row->channel_id.'|'.$row->epg_id.'|'.$row->user_id;
                    $count = $seen[$key] ?? 0;
                    $sourceId = $count === 0
                        ? md5($key)
                        : md5($key.':dup:'.$count);
                    $seen[$key] = $count + 1;

                    DB::table('epg_channels')
                        ->where('id', $row->id)
                        ->update(['source_id' => $sourceId]);
                }
            });

        Schema::table('epg_channels', function (Blueprint $table) {
            $table->dropUnique($this->oldUniqueColumns);
            $table->unique('source_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epg_channels', function (Blueprint $table) {
            $table->dropUnique(['source_id']);
            $table->unique($this->oldUniqueColumns);
            $table->dropColumn('source_id');
        });
    }
};
