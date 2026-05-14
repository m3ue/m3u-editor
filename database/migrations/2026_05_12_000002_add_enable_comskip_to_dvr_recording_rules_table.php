<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dvr_recording_rules', function (Blueprint $table): void {
            $table->boolean('enable_comskip')->nullable()->default(null)->after('enabled');
        });
    }

    public function down(): void
    {
        Schema::table('dvr_recording_rules', function (Blueprint $table): void {
            $table->dropColumn('enable_comskip');
        });
    }
};
