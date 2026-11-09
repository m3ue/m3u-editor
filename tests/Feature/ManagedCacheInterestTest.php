<?php

use App\Models\CachedContentFile;
use App\Models\ManagedCacheInterest;
use App\Models\MediaServerIntegration;
use App\Services\ManagedCacheControlService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('serializes concurrent interest retries through Redis and PostgreSQL', function (): void {
    if (DB::getDriverName() !== 'pgsql' || env('MANAGED_CACHE_REDIS_INTEGRATION') !== '1') {
        $this->markTestSkipped('Requires isolated PostgreSQL and Redis services.');
    }
    Http::preventStrayRequests();
    config(['cache.default' => 'redis', 'cache.prefix' => 'interest-'.Str::uuid().':']);
    app()->forgetInstance('cache.store');
    $file = CachedContentFile::factory()->completed()->create();
    $integration = MediaServerIntegration::factory()->create(['user_id' => $file->user_id]);
    $mapping = (string) Str::uuid();
    $connection = DB::connection();
    $connection->commit();
    $workers = [];
    try {
        $connection->beginTransaction();
        CachedContentFile::whereKey($file->id)->lockForUpdate()->firstOrFail();
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['cache.default' => 'redis', 'cache.prefix' => $argv[4]]);
$app->forgetInstance('cache.store');
echo app(App\Services\ManagedCacheControlService::class)->recordInterest(App\Models\CachedContentFile::findOrFail($argv[1]), App\Models\MediaServerIntegration::findOrFail($argv[2]), $argv[3], 'episode:concurrent', '1080p', 'profile-a')->id;
PHP;
        foreach ([1, 2] as $worker) {
            $process = new Process([PHP_BINARY, '-r', $code, (string) $file->id, (string) $integration->id, $mapping, config('cache.prefix')], base_path(), [
                'DB_CONNECTION' => config('database.default'),
                'TEST_DB_DATABASE' => $connection->getDatabaseName(),
                'DB_DATABASE' => $connection->getDatabaseName(),
            ]);
            $process->setTimeout(15);
            $process->start();
            $workers[] = $process;
        }
        $deadline = microtime(true) + 8;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = (int) DB::selectOne("select count(*) as count from pg_stat_activity where datname = current_database() and pid <> pg_backend_pid() and wait_event_type = 'Lock' and query like '%cached_content_files%'")->count;
            if ($waiting === 2) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline && $workers[0]->isRunning() && $workers[1]->isRunning());
        expect($waiting)->toBe(2, implode("\n", array_map(fn (Process $process): string => $process->getOutput().$process->getErrorOutput(), $workers)));
        $connection->commit();
        foreach ($workers as $process) {
            $process->wait();
            expect($process->getErrorOutput())->toBe('')->and($process->isSuccessful())->toBeTrue();
        }
        expect($workers[0]->getOutput())->toBe($workers[1]->getOutput())
            ->and(ManagedCacheInterest::where('cached_content_file_id', $file->id)->count())->toBe(1);
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($workers as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        $file->delete();
        $integration->user->delete();
        $connection->beginTransaction();
    }
});

it('allows repeated batch targets while concurrent interest retries await the outer transaction', function (bool $commit): void {
    if (DB::getDriverName() !== 'pgsql' || env('MANAGED_CACHE_REDIS_INTEGRATION') !== '1') {
        $this->markTestSkipped('Requires isolated PostgreSQL and Redis services.');
    }
    Http::preventStrayRequests();
    config(['cache.default' => 'redis', 'cache.prefix' => 'interest-batch-'.Str::uuid().':']);
    app()->forgetInstance('cache.store');
    $file = CachedContentFile::factory()->completed()->create();
    $integration = MediaServerIntegration::factory()->create(['user_id' => $file->user_id]);
    $mapping = (string) Str::uuid();
    $connection = DB::connection();
    $connection->commit();
    $worker = null;

    try {
        $connection->beginTransaction();
        $control = app(ManagedCacheControlService::class);
        $first = $control->recordInterest($file, $integration, $mapping, 'episode:batch', '1080p', 'profile-a');
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['cache.default' => 'redis', 'cache.prefix' => $argv[4]]);
$app->forgetInstance('cache.store');
echo app(App\Services\ManagedCacheControlService::class)->recordInterest(App\Models\CachedContentFile::findOrFail($argv[1]), App\Models\MediaServerIntegration::findOrFail($argv[2]), $argv[3], 'episode:batch', '1080p', 'profile-a')->id;
PHP;
        $worker = new Process([PHP_BINARY, '-r', $code, (string) $file->id, (string) $integration->id, $mapping, config('cache.prefix')], base_path(), [
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => config('database.default'),
            'TEST_DB_DATABASE' => $connection->getDatabaseName(),
            'DB_DATABASE' => $connection->getDatabaseName(),
        ]);
        $worker->setTimeout(15);
        $worker->start();
        $deadline = microtime(true) + 8;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = (int) DB::selectOne("select count(*) as count from pg_stat_activity where datname = current_database() and pid <> pg_backend_pid() and wait_event_type = 'Lock' and query like '%cached_content_files%'")->count;
            if ($waiting === 1) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline && $worker->isRunning());
        expect($waiting)->toBe(1, $worker->getErrorOutput());

        $retry = $control->recordInterest($file, $integration, $mapping, 'episode:batch', '1080p', 'profile-a');
        $variant = $control->recordInterest($file, $integration, $mapping, 'episode:batch', '720p', 'profile-a');
        expect($retry->id)->toBe($first->id)
            ->and($variant->id)->not->toBe($first->id)
            ->and($worker->isRunning())->toBeTrue();
        if ($commit) {
            $connection->commit();
        } else {
            $connection->rollBack();
        }
        $worker->wait();
        expect($worker->getErrorOutput())->toBe('')
            ->and($worker->isSuccessful())->toBeTrue()
            ->and(ManagedCacheInterest::where('cached_content_file_id', $file->id)->count())->toBe($commit ? 2 : 1);
        if ($commit) {
            expect($worker->getOutput())->toBe((string) $first->id);
        } else {
            expect(ManagedCacheInterest::find($first->id))->toBeNull()
                ->and(ManagedCacheInterest::find($variant->id))->toBeNull();
        }
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        if ($worker?->isRunning()) {
            $worker->stop();
        }
        $file->delete();
        $integration->user->delete();
        $connection->beginTransaction();
    }
})->with(['commit' => true, 'rollback' => false]);

uses(RefreshDatabase::class);

beforeEach(function () {
    Bus::fake();
});

it('persists a managed cache interest with foreign keys', function () {
    $file = CachedContentFile::factory()->completed()->create();
    $integration = MediaServerIntegration::factory()->create(['user_id' => $file->user_id]);

    $interest = ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $integration->id,
        'mapping_uuid' => (string) Str::uuid(),
        'canonical_id' => 'tmdb:550',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
    ]);

    expect($interest->exists)->toBeTrue()
        ->and($interest->cached_content_file_id)->toBe($file->id)
        ->and($interest->integration_id)->toBe($integration->id);
});

it('enforces unique interest per file/integration/mapping/consumer tuple', function () {
    $file = CachedContentFile::factory()->completed()->create();
    $integration = MediaServerIntegration::factory()->create(['user_id' => $file->user_id]);
    $uuid = (string) Str::uuid();

    ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $integration->id,
        'mapping_uuid' => $uuid,
        'canonical_id' => 'tmdb:550',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
    ]);

    expect(fn () => ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $integration->id,
        'mapping_uuid' => $uuid,
        'canonical_id' => 'tmdb:550',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('allows duplicate consumers across different files', function () {
    $integration = MediaServerIntegration::factory()->create();
    $fileA = CachedContentFile::factory()->completed()->create();
    $fileB = CachedContentFile::factory()->completed()->create();

    ManagedCacheInterest::create([
        'cached_content_file_id' => $fileA->id,
        'integration_id' => $integration->id,
        'mapping_uuid' => (string) Str::uuid(),
        'canonical_id' => 'tmdb:550',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
    ]);

    $second = ManagedCacheInterest::create([
        'cached_content_file_id' => $fileB->id,
        'integration_id' => $integration->id,
        'mapping_uuid' => (string) Str::uuid(),
        'canonical_id' => 'tmdb:550',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
    ]);

    expect($second->exists)->toBeTrue();
});

it('deletes interest metadata without deleting the cached file', function () {
    $file = CachedContentFile::factory()->completed()->create();
    $integration = MediaServerIntegration::factory()->create(['user_id' => $file->user_id]);
    $interest = ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $integration->id,
        'mapping_uuid' => (string) Str::uuid(),
        'canonical_id' => 'tmdb:550',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
    ]);

    $interest->delete();

    expect(ManagedCacheInterest::find($interest->id))->toBeNull()
        ->and(CachedContentFile::find($file->id))->not->toBeNull();
});

it('cascades interest cleanup when the integration is deleted', function () {
    $file = CachedContentFile::factory()->completed()->create();
    $integration = MediaServerIntegration::factory()->create(['user_id' => $file->user_id]);
    $interest = ManagedCacheInterest::create([
        'cached_content_file_id' => $file->id,
        'integration_id' => $integration->id,
        'mapping_uuid' => (string) Str::uuid(),
        'canonical_id' => 'tmdb:550',
        'variant_key' => '1080p',
        'consumer_id' => 'profile-a',
    ]);

    $integration->delete();

    expect(ManagedCacheInterest::find($interest->id))->toBeNull()
        ->and(CachedContentFile::find($file->id))->not->toBeNull();
});

it('records distinct canonical and variant interests on the same shared file', function (): void {
    $file = CachedContentFile::factory()->completed()->create();
    $integration = MediaServerIntegration::factory()->create(['user_id' => $file->user_id]);
    $mapping = (string) Str::uuid();
    $control = app(ManagedCacheControlService::class);
    $first = $control->recordInterest($file, $integration, $mapping, 'episode:1', '1080p', 'profile-a');
    $second = $control->recordInterest($file, $integration, $mapping, 'episode:2', '1080p', 'profile-a');
    $third = $control->recordInterest($file, $integration, $mapping, 'episode:2', '720p', 'profile-a');
    $retry = $control->recordInterest($file, $integration, $mapping, 'episode:2', '720p', 'profile-a');

    expect(array_unique([$first->id, $second->id, $third->id]))->toHaveCount(3)
        ->and($retry->id)->toBe($third->id);
    $control->releaseWatched($integration->user, $integration, $mapping, 'episode:2', '720p', 'profile-a', true, 0, true);
    expect($third->fresh()->watched_at)->not->toBeNull()
        ->and($first->fresh()->watched_at)->toBeNull()
        ->and($second->fresh()->watched_at)->toBeNull();
});

it('records an interest on the cache file without changing its owner', function () {
    $file = CachedContentFile::factory()->completed()->create();
    $integration = MediaServerIntegration::factory()->create(['user_id' => $file->user_id]);

    $interest = app(ManagedCacheControlService::class)->recordInterest(
        $file,
        $integration,
        (string) Str::uuid(),
        'movie:tmdb:550',
        '1080p',
        'profile-a',
    );

    expect($interest->cached_content_file_id)->toBe($file->id)
        ->and($interest->integration_id)->toBe($integration->id)
        ->and($file->fresh()->managed_by)->toBeNull();
});
