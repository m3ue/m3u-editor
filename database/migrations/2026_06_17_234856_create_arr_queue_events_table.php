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
        Schema::create('arr_queue_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('arr_integration_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('download_id')->nullable();
            $table->string('external_id')->nullable();   // tmdb_id or tvdb_id
            $table->string('title');
            $table->string('event_type');                // Grab | Download | MovieAdded | SeriesAdd | ManualInteractionRequired
            $table->string('status');                    // monitored | grabbing | downloading | imported | manual_required
            $table->string('quality')->nullable();
            $table->string('release_title')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->unsignedSmallInteger('progress')->default(0);
            $table->timestamp('last_event_at');
            $table->timestamps();

            $table->index(['arr_integration_id', 'download_id']);
            $table->index(['arr_integration_id', 'external_id']);
            $table->index(['user_id', 'last_event_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arr_queue_events');
    }
};
