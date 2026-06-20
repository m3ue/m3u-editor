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
        Schema::table('channels', function (Blueprint $table) {
            $table->timestamp('last_scrubbed_at')->nullable()->after('probe_enabled');
            $table->string('last_scrubber_result')->nullable()->after('last_scrubbed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('channels', function (Blueprint $table) {
            $table->dropColumn(['last_scrubbed_at', 'last_scrubber_result']);
        });
    }
};
