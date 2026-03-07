<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * This migration creates Laravel's default queue tables (jobs, job_batches, failed_jobs).
     * It must be skipped when running against the 'jobs' database connection
     * (php artisan migrate --database=jobs), because that connection uses a separate
     * SQLite database with a custom 'jobs' table schema for playlist/EPG import tracking.
     * Without this guard, this migration would create the wrong-schema 'jobs' table first,
     * preventing the custom migration (2025_02_13_215803) from creating the correct one.
     */
    public function up(): void
    {
        // Skip when running on the custom 'jobs' SQLite connection to avoid schema conflicts.
        if ($this->getConnection() === 'jobs' || config('database.default') === 'jobs') {
            return;
        }

        if (! Schema::hasTable('jobs')) {

            Schema::create('jobs', function (Blueprint $table) {
                $table->id();
                $table->string('queue')->index();
                $table->longText('payload');
                $table->unsignedTinyInteger('attempts');
                $table->unsignedInteger('reserved_at')->nullable();
                $table->unsignedInteger('available_at');
                $table->unsignedInteger('created_at');
            });

            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });

            Schema::create('failed_jobs', function (Blueprint $table) {
                $table->id();
                $table->string('uuid')->unique();
                $table->text('connection');
                $table->text('queue');
                $table->longText('payload');
                $table->longText('exception');
                $table->timestamp('failed_at')->useCurrent();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if ($this->getConnection() === 'jobs' || config('database.default') === 'jobs') {
            return;
        }

        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
