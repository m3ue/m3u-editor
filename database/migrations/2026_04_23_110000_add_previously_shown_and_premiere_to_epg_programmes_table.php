<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('epg_programmes', function (Blueprint $table) {
            if (! Schema::hasColumn('epg_programmes', 'previously_shown')) {
                $table->boolean('previously_shown')->default(false)->after('is_new');
            }
            if (! Schema::hasColumn('epg_programmes', 'premiere')) {
                $table->boolean('premiere')->default(false)->after('previously_shown');
            }
        });
    }

    public function down(): void
    {
        Schema::table('epg_programmes', function (Blueprint $table) {
            if (Schema::hasColumn('epg_programmes', 'previously_shown')) {
                $table->dropColumn('previously_shown');
            }
            if (Schema::hasColumn('epg_programmes', 'premiere')) {
                $table->dropColumn('premiere');
            }
        });
    }
};
