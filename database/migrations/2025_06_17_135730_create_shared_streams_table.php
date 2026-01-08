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

        Schema::create('shared_streams', function (Blueprint $table) {
            $table->id();
            $table->string('stream_id')->unique()->index();
            $table->string('source_url', 2048);
            $table->enum('format', ['ts', 'hls'])->default('ts');
            $table->enum('status', ['starting', 'active', 'stopping', 'stopped', 'error'])->default('starting');

            // Process information
            $table->unsignedInteger('process_id')->nullable();
            $table->string('buffer_path')->nullable();
            $table->unsignedBigInteger('buffer_size')->default(0);

            // Client tracking
            $table->unsignedInteger('client_count')->default(0);
            $table->timestamp('last_client_activity')->nullable();

            // Stream metadata
            $table->jsonb('stream_info')->nullable();
            $table->jsonb('ffmpeg_options')->nullable();

            // Performance metrics
            $table->unsignedBigInteger('bytes_transferred')->default(0);
            $table->unsignedInteger('bandwidth_kbps')->default(0);
            $table->timestamp('health_check_at')->nullable();
            $table->string('health_status')->default('unknown');

            // Timestamps
            $table->timestamp('started_at')->nullable();
            $table->timestamp('stopped_at')->nullable();
            $table->timestamps();

            // Indexes for performance
            $table->index(['status', 'started_at']);
            $table->index(['format', 'status']);
            $table->index('last_client_activity');
        });

        Schema::create('shared_stream_clients', function (Blueprint $table) {
            $table->id();
            $table->string('stream_id')->index();
            $table->string('client_id')->index();
            $table->string('ip_address', 45);
            $table->string('user_agent', 512)->nullable();
            $table->timestamp('connected_at');
            $table->timestamp('last_activity_at');
            $table->unsignedBigInteger('bytes_received')->default(0);
            $table->enum('status', ['connected', 'disconnected'])->default('connected');
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('stream_id')->references('stream_id')->on('shared_streams')->cascadeOnDelete();

            // Indexes
            $table->index(['stream_id', 'status']);
            $table->index('last_activity_at');
            $table->unique(['stream_id', 'client_id']);
        });

        Schema::create('shared_stream_stats', function (Blueprint $table) {
            $table->id();
            $table->string('stream_id')->index();
            $table->timestamp('recorded_at');
            $table->unsignedInteger('client_count');
            $table->unsignedInteger('bandwidth_kbps');
            $table->unsignedBigInteger('buffer_size');
            $table->jsonb('performance_metrics')->nullable();
            $table->timestamps();

            // Foreign key constraint
            $table->foreign('stream_id')->references('stream_id')->on('shared_streams')->cascadeOnDelete();

            // Indexes for time-series data
            $table->index(['stream_id', 'recorded_at']);
            $table->index('recorded_at');
        });

        Schema::enableForeignKeyConstraints();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shared_stream_stats');
        Schema::dropIfExists('shared_stream_clients');
        Schema::dropIfExists('shared_streams');
    }
};
