<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            if (! Schema::hasColumn('networks', 'broadcast_transcode_session_id')) {
                $table->string('broadcast_transcode_session_id')->nullable()->after('broadcast_restart_locked');
            }
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn('broadcast_transcode_session_id');
        });
    }
};
