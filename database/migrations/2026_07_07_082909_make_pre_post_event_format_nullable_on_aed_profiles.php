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
        Schema::table('aed_profiles', function (Blueprint $table): void {
            $table->string('pre_event_format')->nullable()->change();
            $table->string('post_event_format')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('aed_profiles', function (Blueprint $table): void {
            $table->string('pre_event_format')->nullable(false)->default('Live in {time_until}: {title}')->change();
            $table->string('post_event_format')->nullable(false)->default('Signing Off')->change();
        });
    }
};
