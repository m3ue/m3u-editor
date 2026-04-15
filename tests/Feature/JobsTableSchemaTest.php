<?php

/**
 * Tests for the jobs table schema integrity.
 *
 * Verifies that:
 * - The Laravel default queue migration (0001_01_01_000002) skips when running on the 'jobs' connection
 * - The custom jobs table has the correct schema (title, batch_no, payload, variables)
 * - The ensureJobsTableExists() safety net detects and fixes wrong-schema tables
 */

use App\Models\Job;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Isolate the jobs connection to a temp database so parallel test processes
// don't interfere with each other via the shared jobs.sqlite file.
beforeEach(function () {
    $this->tempJobsDb = sys_get_temp_dir().'/jobs_test_'.uniqid().'.sqlite';
    touch($this->tempJobsDb);
    config(['database.connections.jobs.database' => $this->tempJobsDb]);
    DB::purge('jobs');

    // Set up the correct schema via the custom migration
    $migration = require database_path('migrations/2025_02_13_215803_create_jobs_table.php');
    $migration->up();
});

afterEach(function () {
    DB::purge('jobs');
    config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);
    @unlink($this->tempJobsDb);
});

test('custom jobs table has correct schema with title column', function () {
    // The jobs table on the 'jobs' connection should have our custom columns
    expect(Schema::connection('jobs')->hasTable('jobs'))->toBeTrue();
    expect(Schema::connection('jobs')->hasColumn('jobs', 'title'))->toBeTrue();
    expect(Schema::connection('jobs')->hasColumn('jobs', 'batch_no'))->toBeTrue();
    expect(Schema::connection('jobs')->hasColumn('jobs', 'payload'))->toBeTrue();
    expect(Schema::connection('jobs')->hasColumn('jobs', 'variables'))->toBeTrue();
    expect(Schema::connection('jobs')->hasColumn('jobs', 'created_at'))->toBeTrue();
    expect(Schema::connection('jobs')->hasColumn('jobs', 'updated_at'))->toBeTrue();
});

test('custom jobs table does not have laravel queue columns', function () {
    // The jobs table should NOT have the Laravel default queue columns
    expect(Schema::connection('jobs')->hasColumn('jobs', 'queue'))->toBeFalse();
    expect(Schema::connection('jobs')->hasColumn('jobs', 'attempts'))->toBeFalse();
    expect(Schema::connection('jobs')->hasColumn('jobs', 'reserved_at'))->toBeFalse();
    expect(Schema::connection('jobs')->hasColumn('jobs', 'available_at'))->toBeFalse();
});

test('job model can insert and retrieve records with custom schema', function () {
    $job = Job::create([
        'title' => 'Test job for schema validation',
        'batch_no' => 'test-batch-001',
        'payload' => ['key' => 'value'],
        'variables' => ['var1' => 'test'],
    ]);

    expect($job)->toBeInstanceOf(Job::class);
    expect($job->title)->toBe('Test job for schema validation');
    expect($job->batch_no)->toBe('test-batch-001');
    expect($job->payload)->toBe(['key' => 'value']);
    expect($job->variables)->toBe(['var1' => 'test']);

    // Verify it persisted
    $found = Job::find($job->id);
    expect($found)->not->toBeNull();
    expect($found->title)->toBe('Test job for schema validation');
});

test('laravel default queue migration skips when database default is jobs', function () {
    // Get the migration instance
    $migrationFile = database_path('migrations/0001_01_01_000002_create_jobs_table.php');
    $migration = require $migrationFile;

    // Drop the existing jobs table to test fresh
    Schema::connection('jobs')->dropIfExists('jobs');
    expect(Schema::connection('jobs')->hasTable('jobs'))->toBeFalse();

    // Simulate `php artisan migrate --database=jobs` by setting the default connection
    $originalDefault = config('database.default');
    config(['database.default' => 'jobs']);

    try {
        // Run the migration - it should skip and NOT create the table
        $migration->up();

        // The table should still not exist (migration skipped)
        expect(Schema::connection('jobs')->hasTable('jobs'))->toBeFalse();
    } finally {
        config(['database.default' => $originalDefault]);
    }

    // Now run the custom migration to restore the correct table
    $customMigration = require database_path('migrations/2025_02_13_215803_create_jobs_table.php');
    $customMigration->up();

    // Verify the correct schema was created
    expect(Schema::connection('jobs')->hasTable('jobs'))->toBeTrue();
    expect(Schema::connection('jobs')->hasColumn('jobs', 'title'))->toBeTrue();
});

test('ensureJobsTableExists recreates table when wrong schema detected', function () {
    // Drop the correct table and create one with the wrong schema (simulating the bug)
    Schema::connection('jobs')->dropIfExists('jobs');
    Schema::connection('jobs')->create('jobs', function ($table) {
        $table->id();
        $table->string('queue')->index();
        $table->longText('payload');
        $table->unsignedTinyInteger('attempts');
        $table->unsignedInteger('reserved_at')->nullable();
        $table->unsignedInteger('available_at');
        $table->unsignedInteger('created_at');
    });

    // Verify wrong schema is in place
    expect(Schema::connection('jobs')->hasColumn('jobs', 'queue'))->toBeTrue();
    expect(Schema::connection('jobs')->hasColumn('jobs', 'title'))->toBeFalse();

    // Call the safety net method via reflection
    $provider = app(AppServiceProvider::class, ['app' => app()]);
    $method = new ReflectionMethod($provider, 'ensureJobsTableExists');
    $method->invoke($provider);

    // Verify the table was recreated with correct schema
    expect(Schema::connection('jobs')->hasColumn('jobs', 'title'))->toBeTrue();
    expect(Schema::connection('jobs')->hasColumn('jobs', 'batch_no'))->toBeTrue();
    expect(Schema::connection('jobs')->hasColumn('jobs', 'payload'))->toBeTrue();
    expect(Schema::connection('jobs')->hasColumn('jobs', 'variables'))->toBeTrue();

    // Wrong-schema columns should be gone
    expect(Schema::connection('jobs')->hasColumn('jobs', 'queue'))->toBeFalse();
    expect(Schema::connection('jobs')->hasColumn('jobs', 'attempts'))->toBeFalse();
});
