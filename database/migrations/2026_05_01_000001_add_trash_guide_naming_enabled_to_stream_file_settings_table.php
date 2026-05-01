<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stream_file_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('stream_file_settings', 'trash_guide_naming_enabled')) {
                $table->boolean('trash_guide_naming_enabled')
                    ->default(false)
                    ->after('use_stream_stats')
                    ->comment('Explicit opt-in for Trash Guide naming format');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stream_file_settings', function (Blueprint $table) {
            $table->dropColumn('trash_guide_naming_enabled');
        });
    }
};
