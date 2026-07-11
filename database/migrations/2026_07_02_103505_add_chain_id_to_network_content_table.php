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
        Schema::table('network_content', function (Blueprint $table) {
            $table->unsignedBigInteger('chain_id')->nullable()->after('pin_time_of_day');
            $table->index('chain_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('network_content', function (Blueprint $table) {
            $table->dropIndex(['chain_id']);
            $table->dropColumn('chain_id');
        });
    }
};
