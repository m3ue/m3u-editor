<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('playlist_auths', function (Blueprint $table) {
            $table->foreignId('tv_client_profile_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('playlist_auths', function (Blueprint $table) {
            $table->dropConstrainedForeignId('tv_client_profile_id');
        });
    }
};
