<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stream_file_settings', function (Blueprint $table) {
            $table->string('tmdb_id_apply_to')
                ->default('episodes')
                ->after('tmdb_id_format');
        });
    }

    public function down(): void
    {
        Schema::table('stream_file_settings', function (Blueprint $table) {
            $table->dropColumn('tmdb_id_apply_to');
        });
    }
};
