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
        Schema::table('networks', function (Blueprint $table): void {
            if (! Schema::hasColumn('networks', 'broadcast_on_demand')) {
                $table->boolean('broadcast_on_demand')->default(false)->after('broadcast_schedule_enabled');
            }

            if (! Schema::hasColumn('networks', 'broadcast_last_connection_at')) {
                $table->timestamp('broadcast_last_connection_at')->nullable()->after('broadcast_on_demand');
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('networks', function (Blueprint $table): void {
            $table->dropColumn([
                'broadcast_on_demand',
                'broadcast_last_connection_at',
            ]);
        });
    }
};
