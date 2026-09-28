<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('provider_migration_plan_rows', function (Blueprint $table) {
            $table->json('custom_playlist_names')->nullable()->after('source_group');
        });
    }

    public function down(): void
    {
        Schema::table('provider_migration_plan_rows', function (Blueprint $table) {
            $table->dropColumn('custom_playlist_names');
        });
    }
};
