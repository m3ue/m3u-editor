<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('groups', function (Blueprint $table) {
            $table->foreignId('aed_profile_id')
                ->nullable()
                ->after('stream_file_setting_id')
                ->constrained('aed_profiles')
                ->nullOnDelete();
        });

        Schema::table('channels', function (Blueprint $table) {
            $table->foreignId('aed_profile_id')
                ->nullable()
                ->after('epg_channel_id')
                ->constrained('aed_profiles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropForeign(['aed_profile_id']);
            $table->dropColumn('aed_profile_id');
        });

        Schema::table('groups', function (Blueprint $table) {
            $table->dropForeign(['aed_profile_id']);
            $table->dropColumn('aed_profile_id');
        });
    }
};
