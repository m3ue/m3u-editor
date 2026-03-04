<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            if (! Schema::hasColumn('networks', 'broadcast_initial_offset_seconds')) {
                $table->integer('broadcast_initial_offset_seconds')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            if (Schema::hasColumn('networks', 'broadcast_initial_offset_seconds')) {
                $table->dropColumn('broadcast_initial_offset_seconds');
            }
        });
    }
};
