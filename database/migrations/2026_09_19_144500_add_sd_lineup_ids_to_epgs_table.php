<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epgs', function (Blueprint $table) {
            $table->json('sd_lineup_ids')->nullable()->after('sd_lineup_id');
        });
    }

    public function down(): void
    {
        Schema::table('epgs', function (Blueprint $table) {
            $table->dropColumn('sd_lineup_ids');
        });
    }
};
