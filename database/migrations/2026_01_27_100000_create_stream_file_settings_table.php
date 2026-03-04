<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stream_file_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->string('type'); // 'series' or 'vod'
            $table->boolean('enabled')->default(true);
            $table->string('location')->nullable();
            $table->json('path_structure')->nullable();
            $table->json('filename_metadata')->nullable();
            $table->string('tmdb_id_format')->default('square');
            $table->boolean('clean_special_chars')->default(true);
            $table->boolean('remove_consecutive_chars')->default(true);
            $table->string('replace_char')->default('space');
            $table->boolean('name_filter_enabled')->default(false);
            $table->json('name_filter_patterns')->nullable();
            $table->boolean('generate_nfo')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stream_file_settings');
    }
};
