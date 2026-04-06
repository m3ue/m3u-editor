<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->string('dispatcharr_uuid', 36)->nullable()->unique()->after('source_id');
        });

        // Populate existing channels with UUIDs
        $channels = DB::table('channels')->whereNull('dispatcharr_uuid')->pluck('id');
        foreach ($channels as $id) {
            DB::table('channels')
                ->where('id', $id)
                ->update(['dispatcharr_uuid' => Str::uuid()->toString()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('dispatcharr_uuid');
        });
    }
};
