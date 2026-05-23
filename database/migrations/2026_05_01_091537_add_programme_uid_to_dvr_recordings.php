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
        Schema::table('dvr_recordings', function (Blueprint $table): void {
            $table->string('programme_uid', 64)->nullable()->after('epg_programme_data');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dvr_recordings', function (Blueprint $table): void {
            $table->dropColumn('programme_uid');
        });
    }
};
