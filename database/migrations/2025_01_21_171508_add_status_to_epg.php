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
            $table->enum('status', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending')
                ->after('url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('epgs', function (Blueprint $table) {
            //
        });
    }
};
