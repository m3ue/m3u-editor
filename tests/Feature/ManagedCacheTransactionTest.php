<?php

use App\Enums\CachedContentFileStatus;
use App\Enums\CachedContentManagedBy;
use App\Enums\CacheDispatchResult;
use App\Jobs\DownloadCachedContentFile;
use App\Models\CachedContentFile;
use App\Models\Channel;
use App\Services\CachedContentDispatchService;
use App\Settings\GeneralSettings;
use Illuminate\Database\DatabaseTransactionsManager;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    if (env('MANAGED_CACHE_REDIS_INTEGRATION') !== '1') {
        $this->markTestSkipped('Requires an isolated Redis queue.');
    }
    // End the harness transaction to observe real commits; rebuild the schema next test.
    DB::rollBack();
    RefreshDatabaseState::$migrated = false;
    $transactions = new DatabaseTransactionsManager;
    app()->instance('db.transactions', $transactions);
    DB::connection()->setTransactionManager($transactions);
    config(['queue.default' => 'redis', 'queue.connections.redis.after_commit' => false]);
    Bus::fake()->except([DownloadCachedContentFile::class]);
    Http::preventStrayRequests();
    $stale = new GeneralSettings;
    $stale->enable_cache = false;
    app()->instance(GeneralSettings::class, $stale);
    $settings = new GeneralSettings;
    $settings->enable_cache = true;
    $settings->save();
    $this->channel = Channel::factory()->createQuietly([
        'is_vod' => true,
        'enabled' => true,
        'url' => 'http://198.51.100.1/movie/synthetic/account/1.mkv',
    ]);
});

it('only makes managed downloads visible to Redis after the real commit', function (bool $requeue): void {
    if ($requeue) {
        CachedContentFile::factory()->forItem($this->channel)->failed()->create([
            'managed_by' => CachedContentManagedBy::ManagedPublishing,
        ]);
    }
    $queuedBefore = Queue::connection('redis')->size('cache');
    expect(DB::transactionLevel())->toBe(0);
    DB::transaction(function () use ($queuedBefore): void {
        expect(app(CachedContentDispatchService::class)->dispatchManaged($this->channel))->toBe(CacheDispatchResult::Queued)
            ->and(Queue::connection('redis')->size('cache'))->toBe($queuedBefore);
    });
    expect(Queue::connection('redis')->size('cache'))->toBe($queuedBefore + 1)
        ->and(CachedContentFile::sole()->status)->toBe(CachedContentFileStatus::Pending);
})->with([false, true]);

it('discards managed enqueue callbacks on a real transaction rollback', function (bool $requeue): void {
    if ($requeue) {
        CachedContentFile::factory()->forItem($this->channel)->failed()->create([
            'managed_by' => CachedContentManagedBy::ManagedPublishing,
        ]);
    }
    $queuedBefore = Queue::connection('redis')->size('cache');
    expect(DB::transactionLevel())->toBe(0);
    expect(fn () => DB::transaction(function (): void {
        expect(app(CachedContentDispatchService::class)->dispatchManaged($this->channel))->toBe(CacheDispatchResult::Queued);
        throw new RuntimeException('synthetic rollback');
    }))->toThrow(RuntimeException::class, 'synthetic rollback');
    expect(Queue::connection('redis')->size('cache'))->toBe($queuedBefore);
    if ($requeue) {
        expect(CachedContentFile::sole()->status)->toBe(CachedContentFileStatus::Failed);
    } else {
        expect(CachedContentFile::count())->toBe(0);
    }
})->with([false, true]);
