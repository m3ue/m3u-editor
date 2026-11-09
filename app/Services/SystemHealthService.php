<?php

namespace App\Services;

use App\Facades\GitInfo;
use App\Providers\VersionServiceProvider;
use Composer\InstalledVersions;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Number;
use PDO;

/**
 * Collects the health checks shown on the dashboard System Health widget and
 * the fuller phpinfo-style report shown on the Tools > System Health page.
 */
class SystemHealthService
{
    public const REPORT_CACHE_KEY = 'system_health_report';

    private const REPORT_TTL_SECONDS = 30;

    /**
     * disk_total_space() on the container's overlay filesystem is unreliable
     * (observed returning a value only a few KB above disk_free_space() on
     * ZFS-backed Docker hosts, where the overlay's reported "total" tracks
     * used+free rather than the real volume size). Only free space is shown,
     * so "low disk" is judged against an absolute floor instead of a percent.
     */
    private const LOW_DISK_THRESHOLD_BYTES = 5 * 1024 * 1024 * 1024;

    public function __construct(protected EmbeddedPostgresStatus $embeddedPostgresStatus) {}

    /**
     * Container processes started by docker/8.4/supervisord.conf, keyed by
     * service, matched against each /proc/<pid>/cmdline. nginx, php-fpm and
     * postgres child processes rewrite their titles, so the patterns match the
     * rewritten master titles as well as the original binary paths.
     *
     * @var array<string, string>
     */
    private const PROCESS_PATTERNS = [
        'supervisord' => '/(^|\/)supervisord(\s|$)/',
        'nginx' => '/^nginx: master|(^|\/)nginx\s/',
        'php_fpm' => '/^php-fpm/',
        'queue' => '/artisan horizon/',
        'websocket' => '/artisan reverb:start/',
        'scheduler' => '/(^|\/)crond(\s|$)/',
        'postgresql' => '/^(\S*\/)?postgres(\s|:|$)/',
        'redis' => '/(^|\/)redis-server(\s|$)/',
        'm3u_proxy' => '/m3u-proxy\/main\.py/',
        'network_broadcast' => '/artisan network:broadcast/',
    ];

    /**
     * Pass/fail checks for the dashboard widget.
     *
     * @return list<array{key: string, label: string, ok: bool, detail: string}>
     */
    public function checks(): array
    {
        $checks = [];

        $updateAvailable = $this->safe(fn () => VersionServiceProvider::updateAvailable(), false);
        $checks[] = [
            'key' => 'version',
            'label' => __('Version'),
            'ok' => ! $updateAvailable,
            'detail' => $updateAvailable ? __('Update available') : __('Up to date'),
        ];

        $databaseOk = $this->safe(fn () => DB::connection()->getPdo() !== null);
        $checks[] = [
            'key' => 'database',
            'label' => __('Database'),
            'ok' => $databaseOk,
            'detail' => $databaseOk
                ? $this->safe(fn () => DB::connection()->getDriverName(), __('Connected'))
                : __('Unavailable'),
        ];

        // Only surface this when pg-cluster.sh last boot actually hit a
        // problem (a failed upgrade attempt, or a warning such as a refused
        // rollback) - most installs never touch POSTGRES_VERSION and have no
        // status file at all.
        $postgresStatus = $this->embeddedPostgresStatus->read();
        if ($postgresStatus && (($postgresStatus['upgrade']['result'] ?? null) === 'failed' || filled($postgresStatus['warning'] ?? null))) {
            $failed = ($postgresStatus['upgrade']['result'] ?? null) === 'failed';
            $checks[] = [
                'key' => 'postgres_upgrade',
                'label' => __('PostgreSQL upgrade'),
                'ok' => false,
                'detail' => $failed
                    ? __('Upgrade to :version failed', ['version' => $postgresStatus['upgrade']['to'] ?? '?'])
                    : __('Warning'),
            ];
        }

        // Only surface Redis when the app is actually backed by it (a SQLite-only
        // install may run file cache + sync queue and have no Redis at all).
        if ($this->usesRedis()) {
            $redisOk = $this->safe(fn () => (bool) Redis::connection()->ping());
            $checks[] = [
                'key' => 'redis',
                'label' => __('Redis'),
                'ok' => $redisOk,
                'detail' => $redisOk ? __('Connected') : __('Unavailable'),
            ];
        }

        // Debug mode. A production instance left with APP_DEBUG=true leaks
        // stack traces and config on any error - a real, stable config-health
        // signal that never needs to be live.
        $debug = (bool) config('app.debug');
        $isProduction = app()->environment('production');
        $checks[] = [
            'key' => 'debug',
            'label' => __('Debug mode'),
            'ok' => ! ($debug && $isProduction),
            'detail' => match (true) {
                ! $debug => __('Disabled'),
                $isProduction => __('Enabled'),
                default => __('Enabled (:env)', ['env' => app()->environment()]),
            },
        ];

        $free = $this->safe(fn () => disk_free_space(base_path()), null);
        $free = is_numeric($free) ? (int) $free : null;
        $checks[] = [
            'key' => 'disk',
            'label' => __('Disk free'),
            'ok' => $free === null || $free >= self::LOW_DISK_THRESHOLD_BYTES,
            'detail' => $free !== null
                ? __(':size free', ['size' => Number::fileSize($free, precision: 1)])
                : __('Unknown'),
        ];

        return $checks;
    }

    /**
     * Full system report for the System Health page, cached briefly so page
     * polling and repeat visits do not re-probe every service.
     *
     * @return array<string, mixed>
     */
    public function report(bool $refresh = false): array
    {
        if ($refresh) {
            Cache::forget(self::REPORT_CACHE_KEY);
        }

        return Cache::remember(self::REPORT_CACHE_KEY, self::REPORT_TTL_SECONDS, fn (): array => [
            'checks' => $this->checks(),
            'application' => $this->applicationInfo(),
            'php' => $this->phpInfo(),
            'os' => $this->osInfo(),
            'database' => $this->databaseInfo(),
            'cache' => $this->cacheInfo(),
            'proxy' => $this->proxyInfo(),
            'services' => $this->services(),
            'generated_at' => now()->toDateTimeString(),
        ]);
    }

    /**
     * Whether any core subsystem (cache, queue, session, broadcasting) is
     * actually configured to use Redis.
     */
    public function usesRedis(): bool
    {
        return in_array('redis', $this->drivers(), true);
    }

    /**
     * @return array<string, mixed>
     */
    protected function applicationInfo(): array
    {
        $buildDate = $this->safe(fn () => GitInfo::getBuildDate(), null);

        return [
            'version' => VersionServiceProvider::getVersion(),
            'latest_version' => $this->safe(fn () => VersionServiceProvider::getRemoteVersion() ?: null, null),
            'branch' => $this->safe(fn () => GitInfo::getBranch(), null),
            'commit' => $this->safe(fn () => ($commit = GitInfo::getCommit()) ? substr($commit, 0, 12) : null, null),
            'build_date' => $buildDate ?: null,
            'environment' => app()->environment(),
            'debug' => (bool) config('app.debug'),
            'timezone' => config('app.timezone'),
            'url' => config('app.url'),
            'laravel_version' => app()->version(),
            'filament_version' => $this->packageVersion('filament/filament'),
            'livewire_version' => $this->packageVersion('livewire/livewire'),
        ];
    }

    /**
     * Values come from the PHP SAPI serving this request (php-fpm in the
     * container); queue workers and the scheduler run the CLI binary.
     *
     * @return array<string, mixed>
     */
    protected function phpInfo(): array
    {
        $opcache = $this->safe(fn () => function_exists('opcache_get_status') ? (opcache_get_status(false) ?: null) : null, null);
        $extensions = get_loaded_extensions();
        natcasesort($extensions);

        return [
            'version' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'ini_file' => php_ini_loaded_file() ?: null,
            'memory_limit' => ini_get('memory_limit') ?: null,
            'max_execution_time' => ini_get('max_execution_time'),
            'upload_max_filesize' => ini_get('upload_max_filesize') ?: null,
            'post_max_size' => ini_get('post_max_size') ?: null,
            'opcache' => (bool) ($opcache['opcache_enabled'] ?? false),
            'jit' => (bool) ($opcache['jit']['on'] ?? false),
            'extensions' => array_values($extensions),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function osInfo(): array
    {
        $osRelease = $this->readFile('/etc/os-release');
        preg_match('/^PRETTY_NAME="?([^"\n]+)"?$/m', (string) $osRelease, $osName);

        $containerized = file_exists('/.dockerenv');

        // /proc/meminfo and /proc/cpuinfo are views onto the host kernel, not
        // namespaced per-container, so they report the Docker host's totals
        // (e.g. the TrueNAS host) rather than this container's cgroup limits.
        // Prefer the cgroup when containerized and fall back to /proc otherwise.
        $containerMemory = $containerized ? $this->containerMemory() : null;
        $meminfo = (string) $this->readFile('/proc/meminfo');
        $memoryKb = fn (string $field): ?int => preg_match('/^'.$field.':\s+(\d+)\s+kB/m', $meminfo, $match) ? (int) $match[1] * 1024 : null;

        $cpuCount = $containerized ? $this->containerCpuCount() : null;
        $cpuCount ??= (preg_match_all('/^processor\s*:/m', (string) $this->readFile('/proc/cpuinfo')) ?: null);

        // /proc/uptime is likewise host uptime; approximate container uptime
        // from PID 1's start time instead.
        $uptime = $containerized ? $this->containerUptimeSeconds() : null;
        if ($uptime === null) {
            $raw = $this->readFile('/proc/uptime');
            $uptime = $raw !== null ? (int) (float) $raw : null;
        }

        $load = $this->safe(fn () => sys_getloadavg() ?: null, null);

        return [
            'name' => $osName[1] ?? php_uname('s').' '.php_uname('r'),
            'kernel' => php_uname('r'),
            'architecture' => php_uname('m'),
            'hostname' => gethostname() ?: null,
            'container' => $containerized,
            'cpu_count' => $cpuCount,
            'memory_total' => $containerMemory['total'] ?? $memoryKb('MemTotal'),
            'memory_available' => $containerMemory['available'] ?? $memoryKb('MemAvailable'),
            'load_average' => $load ? implode(' / ', array_map(fn ($value) => number_format($value, 2), $load)) : null,
            'uptime_seconds' => $uptime,
            // disk_total_space() is unreliable on the container's overlay
            // filesystem (see LOW_DISK_THRESHOLD_BYTES), so only free space
            // is reported here.
            'disk_free' => $this->safe(fn () => (int) disk_free_space(base_path()), null),
            'ffmpeg_version' => $this->binaryVersion((string) config('dvr.ffmpeg_path', '/usr/bin/ffmpeg'), '/ffmpeg version (\S+)/'),
            'comskip' => is_executable((string) config('dvr.comskip_path')),
        ];
    }

    /**
     * Memory limit/usage from the container's cgroup, or null when unset
     * (unconstrained) or not running under cgroup v1/v2 memory accounting.
     *
     * @return array{total: int, available: int}|null
     */
    protected function containerMemory(): ?array
    {
        $max = $this->readFile('/sys/fs/cgroup/memory.max');
        if ($max !== null) {
            $max = trim($max);
            $current = $this->readFile('/sys/fs/cgroup/memory.current');
            if ($max === 'max' || $current === null) {
                return null;
            }

            return ['total' => (int) $max, 'available' => max((int) $max - (int) trim($current), 0)];
        }

        $limit = $this->readFile('/sys/fs/cgroup/memory/memory.limit_in_bytes');
        $usage = $this->readFile('/sys/fs/cgroup/memory/memory.usage_in_bytes');
        if ($limit !== null && $usage !== null) {
            $total = (int) trim($limit);
            // Unconstrained cgroup v1 containers report a huge sentinel
            // instead of a real limit (close to PHP_INT_MAX, rounded to a
            // page boundary) rather than "max".
            if ($total <= 0 || $total > (int) (PHP_INT_MAX / 2)) {
                return null;
            }

            return ['total' => $total, 'available' => max($total - (int) trim($usage), 0)];
        }

        return null;
    }

    /**
     * CPU core count the container is actually entitled to, from its cgroup
     * CFS quota or cpuset, or null when unconstrained.
     */
    protected function containerCpuCount(): ?int
    {
        $quotaCount = null;

        $max = $this->readFile('/sys/fs/cgroup/cpu.max');
        if ($max !== null) {
            [$quota, $period] = array_pad(explode(' ', trim($max)), 2, null);
            if ($quota !== null && $quota !== 'max' && is_numeric($quota) && is_numeric($period) && (int) $period > 0) {
                $quotaCount = max((int) ceil((int) $quota / (int) $period), 1);
            }
        }

        if ($quotaCount === null) {
            $quota = $this->readFile('/sys/fs/cgroup/cpu/cpu.cfs_quota_us');
            $period = $this->readFile('/sys/fs/cgroup/cpu/cpu.cfs_period_us');
            if ($quota !== null && $period !== null && (int) trim($quota) > 0 && (int) trim($period) > 0) {
                $quotaCount = max((int) ceil((int) trim($quota) / (int) trim($period)), 1);
            }
        }

        $cpuset = $this->readFile('/sys/fs/cgroup/cpuset.cpus.effective') ?? $this->readFile('/sys/fs/cgroup/cpuset/cpuset.cpus');
        $cpusetCount = $cpuset !== null ? $this->countCpuSet(trim($cpuset)) : null;

        // A quota and a cpuset are independent restrictions that can both
        // apply at once - the effective count is whichever is narrower.
        if ($quotaCount !== null && $cpusetCount !== null) {
            return min($quotaCount, $cpusetCount);
        }

        return $quotaCount ?? $cpusetCount;
    }

    /**
     * Expands a cpuset list like "0-2,5" into a core count.
     */
    protected function countCpuSet(string $cpuset): int
    {
        $count = 0;
        foreach (explode(',', $cpuset) as $range) {
            if (str_contains($range, '-')) {
                [$start, $end] = explode('-', $range, 2);
                $count += (int) $end - (int) $start + 1;
            } elseif ($range !== '') {
                $count++;
            }
        }

        return $count ?: 1;
    }

    /**
     * Approximates container (not host) uptime from PID 1's start time,
     * since /proc/uptime reports the host's time since boot.
     */
    protected function containerUptimeSeconds(): ?int
    {
        $stat = $this->safe(fn () => @stat('/proc/1') ?: null, null);

        return $stat !== null ? max((int) (now()->timestamp - $stat['ctime']), 0) : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function databaseInfo(): array
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}", []);
        $driver = $config['driver'] ?? null;
        $connected = $this->safe(fn () => DB::connection()->getPdo() !== null);
        $placement = $driver === 'sqlite' ? 'embedded' : $this->placement($config['host'] ?? null);

        return [
            'driver' => $driver,
            'connected' => $connected,
            'server_version' => $connected ? $this->safe(fn () => DB::connection()->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION), null) : null,
            'database' => $driver === 'sqlite' ? basename((string) ($config['database'] ?? '')) : ($config['database'] ?? null),
            'host' => $driver === 'sqlite' ? null : ($config['host'] ?? null),
            'placement' => $placement,
            'size' => $connected ? $this->databaseSize($driver, $config) : null,
            'embedded_postgres' => $driver === 'pgsql' && $placement === 'embedded' ? $this->embeddedPostgresInfo() : null,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function embeddedPostgresInfo(): ?array
    {
        $status = $this->embeddedPostgresStatus->read();
        if ($status === null) {
            return null;
        }

        $retained = $this->safe(fn () => $this->embeddedPostgresStatus->retainedCopies(), []);

        return [
            'active_version' => $status['active_version'] ?? null,
            'upgrade_result' => $status['upgrade']['result'] ?? null,
            'upgrade_from' => $status['upgrade']['from'] ?? null,
            'upgrade_to' => $status['upgrade']['to'] ?? null,
            'upgrade_at' => $status['upgrade']['at'] ?? null,
            'upgrade_message' => $status['upgrade']['message'] ?? null,
            'warning' => $status['warning'] ?? null,
            'retained_bytes' => array_sum(array_column($retained, 'bytes')),
            'has_retained_copies' => $retained !== [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function cacheInfo(): array
    {
        [$cache, $queue, $session, $broadcasting] = $this->drivers();
        $usesRedis = $this->usesRedis();
        $redisConnected = $usesRedis && $this->safe(fn () => (bool) Redis::connection()->ping());
        $server = $redisConnected ? $this->redisInfo('server') : [];
        $memory = $redisConnected ? $this->redisInfo('memory') : [];

        return [
            'cache_driver' => $cache,
            'queue_driver' => $queue,
            'session_driver' => $session,
            'broadcast_driver' => $broadcasting,
            'redis_used' => $usesRedis,
            'redis_connected' => $redisConnected,
            'redis_version' => $server['redis_version'] ?? null,
            'redis_memory' => $memory['used_memory_human'] ?? null,
            'redis_host' => $usesRedis ? config('database.redis.default.host').':'.config('database.redis.default.port') : null,
            'redis_placement' => $usesRedis ? $this->placement(config('database.redis.default.host')) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function proxyInfo(): array
    {
        if (! config('proxy.proxy_integration_enabled', true)) {
            return ['enabled' => false];
        }

        $proxy = app(M3uProxyService::class);
        $response = $this->safe(fn () => $proxy->getProxyInfo(), ['success' => false, 'info' => []]);
        $info = $response['info'] ?? [];

        return [
            'enabled' => true,
            'mode' => $proxy->mode(),
            'reachable' => (bool) ($response['success'] ?? false),
            'version' => $info['version'] ?? null,
            'python_version' => $info['python_version'] ?? null,
            'ffmpeg_version' => $info['ffmpeg_version'] ?? null,
            'streamlink_version' => $info['streamlink_version'] ?? null,
            'ytdlp_version' => $info['ytdlp_version'] ?? null,
            'hardware_acceleration' => ($info['hardware_acceleration']['enabled'] ?? false)
                ? ($info['hardware_acceleration']['type'] ?? null)
                : null,
        ];
    }

    /**
     * Status of each supervisord-managed container service, or null when the
     * app is not running under the container's supervisord (local dev, bare
     * metal), where these processes are not expected to exist. The supervisord
     * socket is root-only (0700), so this reads /proc instead of asking it.
     *
     * @return array<string, string>|null running, stopped, external or disabled
     */
    protected function services(): ?array
    {
        $commands = $this->processCommandLines();
        if ($commands === null || ! $this->isRunning($commands, 'supervisord')) {
            return null;
        }

        $databaseConfig = config('database.connections.'.config('database.default'), []);
        $usesPostgres = ($databaseConfig['driver'] ?? null) === 'pgsql';
        $proxyEnabled = config('proxy.proxy_integration_enabled', true);

        // Where each service is expected to run: in this container, on another
        // host (external), or not at all (unused).
        $placements = [
            'nginx' => config('app.nginx_enabled') ? 'embedded' : 'external',
            'php_fpm' => 'embedded',
            'queue' => 'embedded',
            'websocket' => 'embedded',
            'scheduler' => 'embedded',
            'postgresql' => $usesPostgres ? $this->placement($databaseConfig['host'] ?? null) : 'unused',
            'redis' => $this->usesRedis() ? $this->placement(config('database.redis.default.host')) : 'unused',
            'm3u_proxy' => $proxyEnabled ? app(M3uProxyService::class)->mode() : 'unused',
            'network_broadcast' => config('app.network_broadcast_enabled') ? 'embedded' : 'unused',
        ];

        $services = [];
        foreach ($placements as $service => $placement) {
            $services[$service] = match (true) {
                $this->isRunning($commands, $service) => 'running',
                $placement === 'external' => 'external',
                $placement === 'unused' => 'disabled',
                default => 'stopped',
            };
        }

        return $services;
    }

    /**
     * Cache, queue, session and broadcasting drivers, in that order.
     *
     * @return list<string|null>
     */
    protected function drivers(): array
    {
        return [
            config('cache.stores.'.config('cache.default').'.driver'),
            config('queue.connections.'.config('queue.default').'.driver'),
            config('session.driver'),
            config('broadcasting.connections.'.config('broadcasting.default').'.driver'),
        ];
    }

    /**
     * Embedded when the host points back at this container.
     */
    protected function placement(?string $host): string
    {
        return in_array(strtolower(trim((string) $host)), ['', 'localhost', '127.0.0.1', '::1'], true)
            ? 'embedded'
            : 'external';
    }

    /**
     * @param  array<string, mixed>  $config
     */
    protected function databaseSize(?string $driver, array $config): ?int
    {
        return $this->safe(fn () => match ($driver) {
            'sqlite' => is_file($config['database'] ?? '') ? filesize($config['database']) : null,
            'pgsql' => (int) DB::scalar('select pg_database_size(current_database())'),
            'mysql', 'mariadb' => (int) DB::scalar('select sum(data_length + index_length) from information_schema.tables where table_schema = database()'),
            default => null,
        }, null);
    }

    /**
     * One INFO section from Redis, flattened (predis nests it under the
     * section name, phpredis does not).
     *
     * @return array<string, mixed>
     */
    protected function redisInfo(string $section): array
    {
        $info = $this->safe(fn () => Redis::connection()->command('info', [$section]), []);

        return is_array($info) ? Arr::get($info, ucfirst($section), $info) : [];
    }

    protected function binaryVersion(string $path, string $pattern): ?string
    {
        if (! is_executable($path)) {
            return null;
        }

        return $this->safe(function () use ($path, $pattern) {
            $result = Process::timeout(5)->run([$path, '-version']);

            return preg_match($pattern, $result->output(), $match) ? $match[1] : null;
        }, null);
    }

    protected function packageVersion(string $package): ?string
    {
        return $this->safe(fn () => InstalledVersions::getPrettyVersion($package), null);
    }

    /**
     * @return list<string>|null
     */
    protected function processCommandLines(): ?array
    {
        if (! is_readable('/proc/self/cmdline')) {
            return null;
        }

        $commands = [];
        foreach (glob('/proc/[0-9]*/cmdline') ?: [] as $file) {
            $command = trim(str_replace("\0", ' ', (string) $this->readFile($file)));
            if ($command !== '') {
                $commands[] = $command;
            }
        }

        return $commands;
    }

    /**
     * @param  list<string>  $commands
     */
    protected function isRunning(array $commands, string $service): bool
    {
        return preg_grep(self::PROCESS_PATTERNS[$service], $commands) !== [];
    }

    protected function readFile(string $path): ?string
    {
        if (! @is_readable($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        return $contents === false ? null : $contents;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $fallback
     * @return T
     */
    protected function safe(callable $callback, mixed $fallback = false): mixed
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
