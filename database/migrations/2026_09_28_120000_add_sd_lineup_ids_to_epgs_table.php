<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epgs', function (Blueprint $table) {
            $table->json('sd_lineup_ids')->nullable()->after('sd_lineup_id');
        });

        // Carry each existing single lineup over so current SchedulesDirect EPGs keep syncing.
        // The legacy sd_lineup_id column is left in place (unused) so a rollback stays lossless.
        DB::table('epgs')
            ->whereNotNull('sd_lineup_id')
            ->where('sd_lineup_id', '!=', '')
            ->lazyById()
            ->each(fn (object $epg) => DB::table('epgs')
                ->where('id', $epg->id)
                ->update(['sd_lineup_ids' => json_encode([$epg->sd_lineup_id])]));
    }

    public function down(): void
    {
        Schema::table('epgs', function (Blueprint $table) {
            $table->dropColumn('sd_lineup_ids');
        });
    }
};
