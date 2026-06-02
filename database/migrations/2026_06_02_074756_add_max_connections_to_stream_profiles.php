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
        Schema::table('stream_profiles', function (Blueprint $table) {
            $table->unsignedSmallInteger('max_connections')->nullable()->after('cookies_path');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stream_profiles', function (Blueprint $table) {
            $table->dropColumn('max_connections');
        });
    }
};
