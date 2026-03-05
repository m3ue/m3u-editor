<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlist_viewers', function (Blueprint $table) {
            $table->foreignId('playlist_auth_id')
                ->nullable()
                ->after('is_admin')
                ->constrained('playlist_auths')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('playlist_viewers', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\PlaylistAuth::class);
            $table->dropColumn('playlist_auth_id');
        });
    }
};
