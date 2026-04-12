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

        Schema::create('dvr_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('playlist_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->boolean('enabled')->default(false);
            $table->string('storage_disk')->default('dvr');
            $table->string('storage_path')->default('recordings');
            $table->unsignedSmallInteger('max_concurrent_recordings')->default(2);
            $table->string('ffmpeg_path')->nullable();
            $table->unsignedSmallInteger('default_start_early_seconds')->default(30);
            $table->unsignedSmallInteger('default_end_late_seconds')->default(30);
            $table->boolean('enable_metadata_enrichment')->default(true);
            $table->text('tmdb_api_key')->nullable();
            $table->unsignedInteger('global_disk_quota_gb')->nullable();
            $table->unsignedSmallInteger('retention_days')->nullable();
            $table->timestamps();
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dvr_settings');
    }
};
