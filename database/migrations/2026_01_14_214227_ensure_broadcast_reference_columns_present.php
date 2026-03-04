<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            if (! Schema::hasColumn('networks', 'broadcast_programme_id')) {
                $table->unsignedBigInteger('broadcast_programme_id')->nullable();
            }

            if (! Schema::hasColumn('networks', 'broadcast_initial_offset_seconds')) {
                $table->integer('broadcast_initial_offset_seconds')->nullable();
            }

            // Add foreign key if not present
            // We intentionally avoid adding 'after' to be DB-agnostic
            if (! Schema::hasColumn('networks', 'broadcast_programme_id')) {
                // noop - handled above
            }
        });
    }

    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table) {
            if (Schema::hasColumn('networks', 'broadcast_initial_offset_seconds')) {
                $table->dropColumn('broadcast_initial_offset_seconds');
            }

            if (Schema::hasColumn('networks', 'broadcast_programme_id')) {
                $table->dropColumn('broadcast_programme_id');
            }
        });
    }
};
