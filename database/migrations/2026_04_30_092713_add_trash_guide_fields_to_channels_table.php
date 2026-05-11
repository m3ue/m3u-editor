<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            if (! Schema::hasColumn('channels', 'edition')) {
                $table->string('edition')->nullable()->after('stream_stats_probed_at');
            }

            if (! Schema::hasColumn('channels', 'year')) {
                $table->integer('year')->nullable()->after('edition');
            }
        });
    }

    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['edition', 'year']);
        });
    }
};
