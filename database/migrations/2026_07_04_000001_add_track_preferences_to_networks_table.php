<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            if (! Schema::hasColumn('networks', 'preferred_audio_track')) {
                $table->string('preferred_audio_track')->nullable()->after('audio_codec');
            }

            if (! Schema::hasColumn('networks', 'preferred_subtitle_track')) {
                $table->string('preferred_subtitle_track')->nullable()->after('preferred_audio_track');
            }
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $columns = [];

            if (Schema::hasColumn('networks', 'preferred_audio_track')) {
                $columns[] = 'preferred_audio_track';
            }

            if (Schema::hasColumn('networks', 'preferred_subtitle_track')) {
                $columns[] = 'preferred_subtitle_track';
            }

            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
