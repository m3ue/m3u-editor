<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('dvr_settings', function (Blueprint $table): void {
            $table->boolean('generate_nfo_files')->default(false)->after('enable_metadata_enrichment');
        });
    }

    public function down(): void
    {
        Schema::table('dvr_settings', function (Blueprint $table): void {
            $table->dropColumn('generate_nfo_files');
        });
    }
};
