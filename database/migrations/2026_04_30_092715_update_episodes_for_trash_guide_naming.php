<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->string('title')->nullable()->change();

            if (! Schema::hasColumn('episodes', 'stream_stats')) {
                $table->json('stream_stats')->nullable()->after('info');
            }

            if (! Schema::hasColumn('episodes', 'stream_stats_probed_at')) {
                $table->timestamp('stream_stats_probed_at')->nullable()->after('stream_stats');
            }

            if (! Schema::hasColumn('episodes', 'probe_enabled')) {
                $table->boolean('probe_enabled')->default(false)->after('stream_stats_probed_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('episodes', function (Blueprint $table) {
            $table->string('title')->nullable(false)->change();
            $table->dropColumn(['stream_stats', 'stream_stats_probed_at', 'probe_enabled']);
        });
    }
};
