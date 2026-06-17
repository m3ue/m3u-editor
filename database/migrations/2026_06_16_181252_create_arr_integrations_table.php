<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Sonarr/Radarr content request integrations.
     * One record per (user, playlist, server) — mirrors the DvrSetting per-playlist pattern.
     */
    public function up(): void
    {
        Schema::create('arr_integrations', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type'); // 'sonarr' | 'radarr'
            $table->string('url');
            $table->text('api_key'); // encrypted at app layer via Eloquent cast
            $table->integer('quality_profile_id')->nullable();
            $table->string('quality_profile_name')->nullable();
            $table->string('root_folder_path')->nullable();
            $table->boolean('enabled')->default(true);
            $table->boolean('guest_enabled')->default(false);
            $table->timestamp('last_test_at')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'enabled']);
            $table->index(['playlist_id', 'enabled', 'guest_enabled']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arr_integrations');
    }
};
