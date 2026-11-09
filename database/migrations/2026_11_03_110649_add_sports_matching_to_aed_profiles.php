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
        Schema::table('aed_profiles', function (Blueprint $table) {
            $table->boolean('sports_matching')->default(false)->after('override');
            $table->json('sports_league_ids')->nullable()->after('sports_matching');
            $table->string('sports_title_format')->nullable()->after('sports_league_ids');
            $table->unsignedSmallInteger('sports_event_duration_minutes')->nullable()->after('sports_title_format');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aed_profiles', function (Blueprint $table) {
            $table->dropColumn([
                'sports_matching',
                'sports_league_ids',
                'sports_title_format',
                'sports_event_duration_minutes',
            ]);
        });
    }
};
