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
        Schema::create('media_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_auth_id')->nullable()->constrained('playlist_auths')->cascadeOnDelete();
            $table->foreignId('arr_integration_id')->constrained('arr_integrations')->cascadeOnDelete();
            $table->string('title');
            $table->string('external_id')->nullable();
            $table->string('request_type'); // movie, series, episode
            $table->smallInteger('season_number')->nullable();
            $table->smallInteger('episode_number')->nullable();
            $table->jsonb('payload');
            $table->string('status')->default('pending'); // pending, approved, rejected
            $table->text('notes')->nullable();
            $table->timestamp('requested_at');
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('media_requests');
    }
};
