<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_programmes', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('duration_seconds');
            $table->timestamp('pinned_start_time')->nullable()->after('sort_order');
        });
    }

    public function down(): void
    {
        Schema::table('network_programmes', function (Blueprint $table) {
            $table->dropColumn(['sort_order', 'pinned_start_time']);
        });
    }
};
