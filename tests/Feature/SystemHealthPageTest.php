<?php

declare(strict_types=1);

use App\Filament\Pages\SystemHealth;
use App\Filament\Resources\Playlists\PlaylistResource;
use App\Filament\Widgets\SystemHealthWidget;
use App\Jobs\DeletePostgresRollbackCopies;
use App\Models\User;
use App\Services\SystemHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake([
        '*/info' => Http::response([
            'version' => '9.9.9',
            'python_version' => '3.14.0',
            'ffmpeg_version' => '7.1',
            'hardware_acceleration' => ['enabled' => true, 'type' => 'vaapi'],
        ]),
        '*' => Http::response('', 404),
    ]);
    config()->set('proxy.proxy_integration_enabled', true);
});

/**
 * A SystemHealthService whose /proc process list is replaced by $commands.
 *
 * @param  list<string>|null  $commands
 */
function systemHealthServiceWithProcesses(?array $commands): SystemHealthService
{
    return new class($commands) extends SystemHealthService
    {
        public function __construct(private ?array $commands) {}

        protected function processCommandLines(): ?array
        {
            return $this->commands;
        }
    };
}

it('is only available to admins', function () {
    $this->actingAs(User::factory()->create());
    expect(SystemHealth::canAccess())->toBeFalse();
    $this->get(SystemHealth::getUrl())->assertForbidden();

    $this->actingAs(User::factory()->admin()->create());
    expect(SystemHealth::canAccess())->toBeTrue();
});

it('only links the page in the sidebar for admins', function () {
    $this->actingAs(User::factory()->create());
    $this->get(PlaylistResource::getUrl())->assertOk()->assertDontSee(SystemHealth::getUrl());

    $this->actingAs(User::factory()->admin()->create());
    $this->get(PlaylistResource::getUrl())->assertOk()->assertSee(SystemHealth::getUrl());
});

it('renders the report for an admin', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(SystemHealth::class)
        ->assertOk()
        ->assertSee('Health checks')
        ->assertSee('Container services')
        ->assertSee(PHP_VERSION)
        ->assertSee(app()->version())
        ->assertSee('3.14.0')
        ->assertSee('vaapi');
});

it('shows fresh values after the refresh action', function () {
    $this->actingAs(User::factory()->admin()->create());

    Cache::put(SystemHealthService::REPORT_CACHE_KEY, [
        'checks' => [],
        'php' => ['version' => '0.0.0-stale'],
        'services' => null,
        'generated_at' => 'stale',
    ], 60);

    Livewire::test(SystemHealth::class)
        ->assertSee('0.0.0-stale')
        ->callAction('refresh')
        ->assertNotified()
        ->assertDontSee('0.0.0-stale')
        ->assertSee(PHP_VERSION);
});

it('links the dashboard widget to the page', function () {
    $this->actingAs(User::factory()->admin()->create());

    Livewire::test(SystemHealthWidget::class)
        ->assertSee('View details')
        ->assertSee(SystemHealth::getUrl());
});

it('reports container services by process and expected placement', function () {
    config()->set('app.nginx_enabled', false);
    config()->set('app.network_broadcast_enabled', false);
    config()->set('proxy.external_proxy_enabled', true);
    config()->set('database.redis.default.host', 'redis');
    config()->set('session.driver', 'redis');
    // Pinned so the result does not depend on the suite's DB (CI runs on a local Postgres).
    config()->set('database.connections.'.config('database.default').'.driver', 'sqlite');

    $services = invade(systemHealthServiceWithProcesses([
        '/usr/bin/python3 /usr/bin/supervisord -c /etc/supervisor/conf.d/supervisord.conf',
        'php-fpm: master process (/etc/php84/php-fpm.conf)',
        '/usr/bin/php /var/www/html/artisan horizon',
        '/usr/sbin/crond -f -d 8',
    ]))->services();

    expect($services)
        ->php_fpm->toBe('running')
        ->queue->toBe('running')
        ->scheduler->toBe('running')
        ->websocket->toBe('stopped')
        ->nginx->toBe('external')
        ->m3u_proxy->toBe('external')
        ->redis->toBe('external')
        ->postgresql->toBe('disabled')
        ->network_broadcast->toBe('disabled');
});

it('matches the rewritten titles of the embedded services', function () {
    config()->set('database.connections.'.config('database.default').'.driver', 'pgsql');
    config()->set('database.connections.'.config('database.default').'.host', '127.0.0.1');

    $services = invade(systemHealthServiceWithProcesses([
        'supervisord',
        'nginx: master process /usr/sbin/nginx -g daemon off;',
        '/usr/bin/php /var/www/html/artisan reverb:start --host=0.0.0.0 --port=36800',
        'postgres -D /var/lib/postgresql/data -h 0.0.0.0 -p 5432',
        'redis-server 127.0.0.1:36790',
        '/usr/bin/python3 /opt/m3u-proxy/main.py',
    ]))->services();

    expect($services)
        ->nginx->toBe('running')
        ->websocket->toBe('running')
        ->postgresql->toBe('running')
        ->redis->toBe('running')
        ->m3u_proxy->toBe('running');
});

it('still detects postgresql when supervisord runs the versioned binary (opt-in major upgrade)', function () {
    config()->set('database.connections.'.config('database.default').'.driver', 'pgsql');
    config()->set('database.connections.'.config('database.default').'.host', '127.0.0.1');

    $services = invade(systemHealthServiceWithProcesses([
        'supervisord',
        '/usr/libexec/postgresql18/postgres -D /var/lib/postgresql/data/pg18 -h 0.0.0.0 -p 5432',
    ]))->services();

    expect($services)->postgresql->toBe('running');
});

/**
 * Writes a fake docker/8.4/pg-cluster.sh status file to a throwaway mount
 * directory and points the embedded_postgres_status_path config at it.
 *
 * @param  array<string, mixed>  $status
 */
function fakePostgresStatus(array $status): string
{
    $mount = storage_path('framework/testing/pg-mount-'.uniqid());
    File::ensureDirectoryExists($mount);
    config(['database.embedded_postgres_status_path' => "{$mount}/.m3ue-postgres-status.json"]);
    File::put("{$mount}/.m3ue-postgres-status.json", json_encode($status));

    return $mount;
}

afterEach(function () {
    // Only clean up the throwaway fixture directories fakePostgresStatus()
    // creates - never the real default path, which would delete a live
    // embedded Postgres data directory out from under CI.
    $mount = dirname((string) config('database.embedded_postgres_status_path'));
    if (str_contains($mount, 'framework/testing/pg-mount-')) {
        File::deleteDirectory($mount);
    }
});

it('surfaces a failed PostgreSQL upgrade as a health check', function () {
    fakePostgresStatus([
        'active_version' => '17',
        'upgrade' => ['result' => 'failed', 'from' => '17', 'to' => '18', 'message' => 'disk full'],
        'warning' => '',
    ]);

    $check = collect(app(SystemHealthService::class)->checks())->firstWhere('key', 'postgres_upgrade');

    expect($check)->not->toBeNull()
        ->and($check['ok'])->toBeFalse();
});

it('does not show the PostgreSQL upgrade check when nothing has gone wrong', function () {
    fakePostgresStatus([
        'active_version' => '17',
        'upgrade' => ['result' => 'none'],
        'warning' => '',
    ]);

    expect(collect(app(SystemHealthService::class)->checks())->firstWhere('key', 'postgres_upgrade'))->toBeNull();
});

it('hides the delete-rollback-copy action when there is nothing retained', function () {
    $this->actingAs(User::factory()->admin()->create());
    config()->set('database.connections.'.config('database.default').'.driver', 'pgsql');
    config()->set('database.connections.'.config('database.default').'.host', '127.0.0.1');
    $mount = fakePostgresStatus(['active_version' => '17', 'upgrade' => ['result' => 'none'], 'warning' => '']);
    config(['database.embedded_postgres_status_path' => "{$mount}/.m3ue-postgres-status.json"]);
    File::put("{$mount}/.m3ue-postgres-status.json", json_encode(['active_version' => '17', 'active_path' => $mount, 'upgrade' => ['result' => 'none'], 'warning' => '']));

    Livewire::test(SystemHealth::class)->assertActionHidden('deletePostgresRollbackCopy');
});

it('offers to delete the retained rollback copy after a successful upgrade, and dispatches the job', function () {
    Bus::fake();
    $this->actingAs(User::factory()->admin()->create());
    config()->set('database.connections.'.config('database.default').'.driver', 'pgsql');
    config()->set('database.connections.'.config('database.default').'.host', '127.0.0.1');

    $mount = fakePostgresStatus([]);
    File::ensureDirectoryExists("{$mount}/pg18/base");
    File::put("{$mount}/pg18/PG_VERSION", '18');
    File::put("{$mount}/PG_VERSION", '17');
    File::put("{$mount}/.m3ue-postgres-status.json", json_encode([
        'active_version' => '18',
        'active_path' => "{$mount}/pg18",
        'upgrade' => ['result' => 'upgraded', 'from' => '17', 'to' => '18'],
        'warning' => '',
    ]));

    Livewire::test(SystemHealth::class)
        ->assertSee('Upgraded from 17 to 18')
        ->assertActionVisible('deletePostgresRollbackCopy')
        ->callAction('deletePostgresRollbackCopy')
        ->assertNotified();

    Bus::assertDispatched(DeletePostgresRollbackCopies::class);
});

it('skips the services section outside the container', function () {
    expect(invade(systemHealthServiceWithProcesses(null))->services())->toBeNull()
        ->and(invade(systemHealthServiceWithProcesses(['/usr/sbin/crond -f']))->services())->toBeNull();
});

it('reports only disk free space, not an unreliable overlay-fs total', function () {
    $checks = app(SystemHealthService::class)->checks();
    $disk = collect($checks)->firstWhere('key', 'disk');

    expect($disk['detail'])->not->toContain('%')->not->toContain('of ');
});

/**
 * A SystemHealthService whose /sys/fs/cgroup reads are replaced by $files
 * (path => contents), simulating a containerized cgroup v2 environment.
 *
 * @param  array<string, string>  $files
 */
function systemHealthServiceWithCgroupFiles(array $files): SystemHealthService
{
    return new class($files) extends SystemHealthService
    {
        public function __construct(private array $files) {}

        protected function readFile(string $path): ?string
        {
            return $this->files[$path] ?? null;
        }
    };
}

it('reads container memory and CPU limits from the cgroup, not the host', function () {
    $service = systemHealthServiceWithCgroupFiles([
        '/sys/fs/cgroup/memory.max' => (string) (2 * 1024 * 1024 * 1024)."\n",
        '/sys/fs/cgroup/memory.current' => (string) (512 * 1024 * 1024)."\n",
        '/sys/fs/cgroup/cpu.max' => "200000 100000\n",
    ]);

    expect(invade($service)->containerMemory())->toBe([
        'total' => 2 * 1024 * 1024 * 1024,
        'available' => (2 * 1024 - 512) * 1024 * 1024,
    ])->and(invade($service)->containerCpuCount())->toBe(2);
});

it('respects a narrower cpuset than the cgroup v2 quota allows (issue #1780)', function () {
    $service = systemHealthServiceWithCgroupFiles([
        '/sys/fs/cgroup/cpu.max' => "200000 100000\n",
        '/sys/fs/cgroup/cpuset.cpus.effective' => "0\n",
    ]);

    expect(invade($service)->containerCpuCount())->toBe(1);
});

it('still reports the quota count when the cpuset is not narrower', function () {
    $service = systemHealthServiceWithCgroupFiles([
        '/sys/fs/cgroup/cpu.max' => "200000 100000\n",
        '/sys/fs/cgroup/cpuset.cpus.effective' => "0-1\n",
    ]);

    expect(invade($service)->containerCpuCount())->toBe(2);
});

it('treats an unconstrained cgroup as unknown rather than the host total', function () {
    $service = systemHealthServiceWithCgroupFiles([
        '/sys/fs/cgroup/memory.max' => "max\n",
        '/sys/fs/cgroup/cpu.max' => "max 100000\n",
    ]);

    expect(invade($service)->containerMemory())->toBeNull()
        ->and(invade($service)->containerCpuCount())->toBeNull();
});
