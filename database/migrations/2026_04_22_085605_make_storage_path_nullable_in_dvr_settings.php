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
        Schema::table('dvr_settings', function (Blueprint $table) {
            $table->string('storage_path')->nullable()->default(null)->change();
        });
    }

    public function down(): void
    {
        Schema::table('dvr_settings', function (Blueprint $table) {
            $table->string('storage_path')->nullable(false)->default('recordings')->change();
        });
    }
};
