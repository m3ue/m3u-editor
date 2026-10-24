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
        Schema::table('cached_content_activity_leases', function (Blueprint $table) {
            $table->timestamp('terminal_until')->nullable()->after('last_activity_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('cached_content_activity_leases', function (Blueprint $table) {
            $table->dropColumn('terminal_until');
        });
    }
};
