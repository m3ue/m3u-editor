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
        Schema::table('emby_library_mappings', function (Blueprint $table) {
            $table->timestamp('library_create_requested_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('emby_library_mappings', function (Blueprint $table) {
            $table->dropColumn('library_create_requested_at');
        });
    }
};
