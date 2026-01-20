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
        Schema::table('networks', function (Blueprint $table) {
            if (! Schema::hasColumn('networks', 'schedule_window_days')) {
                $table->unsignedSmallInteger('schedule_window_days')->default(7)->after('schedule_generated_at');
            }
            if (! Schema::hasColumn('networks', 'auto_regenerate_schedule')) {
                $table->boolean('auto_regenerate_schedule')->default(true)->after('schedule_window_days');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            $table->dropColumn(['schedule_window_days', 'auto_regenerate_schedule']);
        });
    }
};
