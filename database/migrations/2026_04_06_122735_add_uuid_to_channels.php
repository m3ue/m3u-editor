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
            $table->string('uuid', 36)->nullable()->unique()->after('id');
        });

        // Populate existing channels with UUIDs
        $channels = DB::table('channels')->whereNull('uuid')->pluck('id');
        foreach ($channels as $id) {
            DB::table('channels')
                ->where('id', $id)
                ->update(['uuid' => Str::orderedUuid()->toString()]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
