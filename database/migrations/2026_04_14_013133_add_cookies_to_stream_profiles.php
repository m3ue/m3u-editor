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
            $table->longText('cookies')->nullable()->after('args');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stream_profiles', function (Blueprint $table) {
            $table->dropColumn('cookies');
        });
    }
};
