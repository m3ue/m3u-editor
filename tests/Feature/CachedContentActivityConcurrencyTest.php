<?php

use App\Models\CachedContentActivityLease;
use App\Models\MediaServerIntegration;
use App\Models\User;
use App\Services\CachedContentActivityService;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

it('serializes concurrent first heartbeats behind a database row lock', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Requires PostgreSQL and independent PHP workers.');
    }

    Bus::fake();
    Http::preventStrayRequests();
    $user = User::factory()->create();
    $integration = MediaServerIntegration::factory()->for($user)->create();
    $leaseId = (string) Str::uuid();
    $target = ['mapping_uuid' => (string) Str::uuid(), 'revision' => str_repeat('a', 64), 'canonical_id' => 'episode:synthetic:1', 'variant_key' => '1080p'];
    $connection = DB::connection();
    $connection->commit();
    $workers = [];

    try {
        $connection->beginTransaction();
        MediaServerIntegration::whereKey($integration->id)->lockForUpdate()->firstOrFail();
        $payload = base64_encode(json_encode([$user->id, $integration->id, $leaseId, $target], JSON_THROW_ON_ERROR));
        $code = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Facades\Bus::fake();
Illuminate\Support\Facades\Http::preventStrayRequests();
[$userId, $integrationId, $leaseId, $target] = json_decode(base64_decode($argv[1]), true, flags: JSON_THROW_ON_ERROR);
$lease = app(App\Services\CachedContentActivityService::class)->acquirePlayback(App\Models\User::findOrFail($userId), App\Models\MediaServerIntegration::findOrFail($integrationId), $leaseId, 1, $target);
echo $lease->id;
PHP;
        foreach ([1, 2] as $worker) {
            $process = new Process([PHP_BINARY, '-r', $code, $payload], base_path(), [
                'APP_ENV' => 'testing',
                'DB_CONNECTION' => config('database.default'),
                'CACHE_STORE' => 'redis',
            ]);
            $process->setTimeout(15);
            $process->start();
            $workers[] = $process;
        }

        $deadline = microtime(true) + 8;
        do {
            DB::select('select pg_stat_clear_snapshot()');
            $waiting = (int) DB::selectOne("select count(*) as count from pg_stat_activity where datname = current_database() and pid <> pg_backend_pid() and wait_event_type = 'Lock' and query like '%media_server_integrations%'")->count;
            if ($waiting === 2) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline && $workers[0]->isRunning() && $workers[1]->isRunning());

        expect($waiting)->toBe(2, implode("\n", array_map(fn (Process $process): string => $process->getOutput().$process->getErrorOutput(), $workers)));
        $connection->commit();
        foreach ($workers as $process) {
            $process->wait();
            expect($process->getErrorOutput())->toBe('')
                ->and($process->isSuccessful())->toBeTrue();
        }
        expect($workers[0]->getOutput())->toBe($workers[1]->getOutput())
            ->and(CachedContentActivityLease::where('lease_id', $leaseId)->count())->toBe(1)
            ->and(app(CachedContentActivityService::class)->hasActivePlayback($target))->toBeTrue();
    } finally {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        foreach ($workers as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        User::whereKey($user->id)->delete();
        $connection->beginTransaction();
    }
});
