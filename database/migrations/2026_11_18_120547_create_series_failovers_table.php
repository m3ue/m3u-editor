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
        Schema::create('series_failovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('series_id')->constrained('series')->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('series_failover_id')->constrained('series')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unsignedInteger('sort')->nullable()->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['series_id', 'series_failover_id']);
            $table->index('series_failover_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('series_failovers');
    }
};
