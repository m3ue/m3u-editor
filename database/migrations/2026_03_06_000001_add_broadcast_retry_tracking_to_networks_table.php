<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->unsignedInteger('broadcast_fail_count')->default(0)->after('broadcast_error');
            $table->timestamp('broadcast_last_failed_at')->nullable()->after('broadcast_fail_count');
            $table->unsignedSmallInteger('broadcast_last_exit_code')->nullable()->after('broadcast_last_failed_at');
            $table->boolean('broadcast_restart_locked')->default(false)->after('broadcast_last_exit_code');
            $table->string('broadcast_transcode_session_id')->nullable()->after('broadcast_restart_locked');
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn([
                'broadcast_fail_count',
                'broadcast_last_failed_at',
                'broadcast_last_exit_code',
                'broadcast_restart_locked',
                'broadcast_transcode_session_id',
            ]);
        });
    }
};
