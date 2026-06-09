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
        Schema::disableForeignKeyConstraints();

        Schema::create('episode_failovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('episode_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('episode_failover_id')->constrained('episodes')->cascadeOnDelete()->cascadeOnUpdate();
            $table->unsignedInteger('sort')->nullable()->default(0);
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->unique(['episode_id', 'episode_failover_id']);
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('episode_failovers');
    }
};
