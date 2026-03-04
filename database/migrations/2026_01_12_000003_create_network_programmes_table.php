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
        Schema::create('network_programmes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('image')->nullable();
            $table->timestamp('start_time');
            $table->timestamp('end_time');
            $table->unsignedInteger('duration_seconds');
            $table->morphs('contentable'); // References the Episode or Channel being played
            $table->timestamps();

            // Index for finding current/upcoming programmes
            $table->index(['network_id', 'start_time', 'end_time'], 'network_programmes_schedule_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('network_programmes');
    }
};
