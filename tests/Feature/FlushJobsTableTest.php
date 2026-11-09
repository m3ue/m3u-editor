<?php

use App\Models\Job;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->tempJobsDb = sys_get_temp_dir().'/jobs_test_'.uniqid().'.sqlite';
    touch($this->tempJobsDb);
    config(['database.connections.jobs.database' => $this->tempJobsDb]);
    DB::purge('jobs');

    $migration = require database_path('migrations/2025_02_13_215803_create_jobs_table.php');
    $migration->up();
});

afterEach(function () {
    DB::purge('jobs');
    config(['database.connections.jobs.database' => database_path('jobs.sqlite')]);

    if (file_exists($this->tempJobsDb)) {
        @unlink($this->tempJobsDb);
    }
});

function createJobFixture(array $overrides = []): Job
{
    return Job::create(array_merge([
        'title' => 'test-job',
        'batch_no' => (string) Str::uuid(),
        'payload' => '[]',
        'variables' => [],
    ], $overrides));
}

it('preserves fresh jobs when clearing only old ones', function () {
    $fresh = createJobFixture(['created_at' => now()]);
    $old = createJobFixture(['created_at' => now()->subDays(2)]);

    $this->artisan('app:flush-jobs-table')
        ->expectsOutputToContain('Clearing: 1 jobs')
        ->assertExitCode(0);

    expect(Job::query()->whereKey($fresh->id)->exists())->toBeTrue()
        ->and(Job::query()->whereKey($old->id)->exists())->toBeFalse();
});

it('clears every job when all is passed regardless of age', function () {
    createJobFixture(['created_at' => now()]);
    createJobFixture(['created_at' => now()->subDays(2)]);

    $this->artisan('app:flush-jobs-table', ['all' => 'all'])
        ->assertExitCode(0);

    expect(Job::query()->count())->toBe(0);
});

it('leaves the table untouched when every job is fresh', function () {
    $fresh = createJobFixture(['created_at' => now()]);

    $this->artisan('app:flush-jobs-table')
        ->expectsOutputToContain('Clearing: 0 jobs')
        ->assertExitCode(0);

    expect(Job::query()->whereKey($fresh->id)->exists())->toBeTrue();
});
