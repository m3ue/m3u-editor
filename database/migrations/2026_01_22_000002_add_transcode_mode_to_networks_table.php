<?php

use App\Enums\TranscodeMode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            if (! Schema::hasColumn('networks', 'transcode_mode')) {
                $table->string('transcode_mode', 20)->default(TranscodeMode::Local->value)->after('hls_list_size');
            }
        });

        // Migrate existing boolean column values to enum
        if (Schema::hasColumn('networks', 'transcode_on_server')) {
            DB::table('networks')->where('transcode_on_server', true)->update(['transcode_mode' => TranscodeMode::Direct->value]);
            DB::table('networks')->where('transcode_on_server', false)->update(['transcode_mode' => TranscodeMode::Local->value]);

            Schema::table('networks', function (Blueprint $table) {
                $table->dropColumn('transcode_on_server');
            });
        }
    }

    public function down(): void
    {
        // Recreate the boolean column and convert values back
        Schema::table('networks', function (Blueprint $table) {
            if (! Schema::hasColumn('networks', 'transcode_on_server')) {
                $table->boolean('transcode_on_server')->default(true)->after('hls_list_size');
            }
        });

        DB::table('networks')->where('transcode_mode', TranscodeMode::Direct->value)->update(['transcode_on_server' => true]);
        DB::table('networks')->where('transcode_mode', '!=', TranscodeMode::Direct->value)->update(['transcode_on_server' => false]);

        Schema::table('networks', function (Blueprint $table) {
            if (Schema::hasColumn('networks', 'transcode_mode')) {
                $table->dropColumn('transcode_mode');
            }
        });
    }
};
