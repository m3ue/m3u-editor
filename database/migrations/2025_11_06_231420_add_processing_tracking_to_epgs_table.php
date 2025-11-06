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
        Schema::table('epgs', function (Blueprint $table) {
            $table->timestamp('processing_started_at')->nullable()->after('processing');
            $table->string('processing_phase')->nullable()->after('processing_started_at')->comment('import, cache, or null');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epgs', function (Blueprint $table) {
            $table->dropColumn(['processing_started_at', 'processing_phase']);
        });
    }
};
