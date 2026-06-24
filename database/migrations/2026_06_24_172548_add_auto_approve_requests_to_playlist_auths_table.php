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
        Schema::table('playlist_auths', function (Blueprint $table) {
            $table->boolean('auto_approve_requests')->default(true)->after('stop_oldest_on_limit');
        });
    }

    public function down(): void
    {
        Schema::table('playlist_auths', function (Blueprint $table) {
            $table->dropColumn('auto_approve_requests');
        });
    }
};
