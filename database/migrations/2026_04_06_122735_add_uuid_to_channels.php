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

        // Populate existing channels with UUIDs in chunks to avoid N+1 updates
        DB::table('channels')->whereNull('uuid')->orderBy('id')->chunkById(500, function ($channels) {
            $cases = '';
            $ids = [];
            foreach ($channels as $channel) {
                $uuid = Str::orderedUuid()->toString();
                $cases .= "WHEN {$channel->id} THEN '{$uuid}' ";
                $ids[] = $channel->id;
            }

            $idList = implode(',', $ids);
            DB::statement("UPDATE channels SET uuid = CASE id {$cases}END WHERE id IN ({$idList})");
        });
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
