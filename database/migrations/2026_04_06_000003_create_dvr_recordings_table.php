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

        Schema::create('dvr_recordings', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('dvr_setting_id')->constrained()->cascadeOnDelete()->cascadeOnUpdate();
            $table->foreignId('dvr_recording_rule_id')->nullable()->constrained()->nullOnDelete()->cascadeOnUpdate();
            $table->foreignId('channel_id')->nullable()->constrained()->nullOnDelete()->cascadeOnUpdate();
            $table->string('status')->default('scheduled'); // DvrRecordingStatus enum
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('season')->nullable();
            $table->unsignedSmallInteger('episode')->nullable();
            $table->dateTime('scheduled_start')->index();
            $table->dateTime('scheduled_end')->index();
            $table->dateTime('actual_start')->nullable();
            $table->dateTime('actual_end')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedBigInteger('file_size_bytes')->nullable();
            $table->text('stream_url')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->dateTime('programme_start')->nullable();
            $table->dateTime('programme_end')->nullable();
            $table->json('epg_programme_data')->nullable();
            $table->unsignedInteger('pid')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index(['dvr_setting_id', 'status']);
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dvr_recordings');
    }
};
