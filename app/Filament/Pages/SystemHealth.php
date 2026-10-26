<?php

namespace App\Filament\Pages;

use App\Jobs\DeletePostgresRollbackCopies;
use App\Services\SystemHealthService;
use Carbon\CarbonInterval;
use Filament\Actions\Action;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Number;

class SystemHealth extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = null;

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'system-health';

    /**
     * Per-request memo of the cached report. Not a public property, so it is
     * never serialized into the Livewire snapshot and each request re-reads
     * the (30 second) cache.
     *
     * @var array<string, mixed>|null
     */
    protected ?array $report = null;

    public static function getNavigationLabel(): string
    {
        return __('System Health');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('Tools');
    }

    public static function canAccess(): bool
    {
        return auth()->check() && auth()->user()->isAdmin();
    }

    public function getTitle(): string|Htmlable
    {
        return __('System Health');
    }

    public function getSubheading(): string|Htmlable|null
    {
        return __('Generated :time', ['time' => $this->value('generated_at')]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refresh')
                ->label(__('Refresh'))
                ->icon('heroicon-o-arrow-path')
                ->color('gray')
                ->action(function (): void {
                    $this->report = app(SystemHealthService::class)->report(refresh: true);
                    Cache::forget('dashboard_system_health_checks');

                    Notification::make()
                        ->title(__('System health refreshed'))
                        ->success()
                        ->send();
                }),
            Action::make('deletePostgresRollbackCopy')
                ->label(__('Delete PostgreSQL rollback copy'))
                ->icon('heroicon-o-trash')
                ->color('danger')
                ->visible(fn (): bool => (bool) $this->value('database.embedded_postgres.has_retained_copies'))
                ->requiresConfirmation()
                ->modalDescription(__('This permanently deletes the retained PostgreSQL cluster(s) from the previous version (about :size). You will no longer be able to roll back to it. The currently active PostgreSQL cluster is not affected.', [
                    'size' => Number::fileSize((int) $this->value('database.embedded_postgres.retained_bytes'), precision: 1),
                ]))
                ->action(function (): void {
                    DeletePostgresRollbackCopies::dispatch();

                    Notification::make()
                        ->title(__('Deleting PostgreSQL rollback copy'))
                        ->body(__('You will be notified when it is complete.'))
                        ->success()
                        ->send();
                }),
        ];
    }

    /**
     * Every entry reads its state lazily through value(): the schema is built
     * before header actions run, so state captured at build time would still
     * show the pre-refresh report.
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('Health checks'))
                ->icon('heroicon-o-heart')
                ->compact()
                ->schema($this->checkEntries()),
            Section::make(__('Container services'))
                ->icon('heroicon-o-server-stack')
                ->description(__('Processes managed by the container\'s supervisor.'))
                ->compact()
                ->schema($this->serviceEntries()),
            Section::make(__('Application'))
                ->icon('heroicon-o-cube')
                ->compact()
                ->schema([
                    $this->entry('application.version', __('Version')),
                    $this->entry('application.latest_version', __('Latest version')),
                    $this->entry('application.branch', __('Branch')),
                    $this->entry('application.commit', __('Commit'))->fontFamily('mono'),
                    $this->entry('application.build_date', __('Build date')),
                    $this->entry('application.environment', __('Environment')),
                    $this->toggleEntry('application.debug', __('Debug mode'), warnWhenOn: true),
                    $this->entry('application.timezone', __('Timezone')),
                    $this->entry('application.url', __('URL'))->extraAttributes(['class' => 'privacy-sensitive']),
                    $this->entry('application.laravel_version', 'Laravel'),
                    $this->entry('application.filament_version', 'Filament'),
                    $this->entry('application.livewire_version', 'Livewire'),
                ]),
            Section::make('PHP')
                ->icon('heroicon-o-code-bracket')
                ->compact()
                ->schema([
                    $this->entry('php.version', __('Version')),
                    $this->entry('php.sapi', __('Server API')),
                    $this->entry('php.ini_file', __('Configuration file'))->fontFamily('mono'),
                    $this->entry('php.memory_limit', __('Memory limit')),
                    $this->entry('php.max_execution_time', __('Max execution time'))
                        ->formatStateUsing(fn (string $state): string => $state === '0' ? __('Unlimited') : $state.'s'),
                    $this->entry('php.upload_max_filesize', __('Upload max file size')),
                    $this->entry('php.post_max_size', __('Post max size')),
                    $this->toggleEntry('php.opcache', 'OPcache'),
                    $this->toggleEntry('php.jit', 'JIT'),
                ]),
            Section::make(__('Operating system'))
                ->icon('heroicon-o-cpu-chip')
                ->compact()
                ->schema([
                    $this->entry('os.name', __('Operating system')),
                    $this->entry('os.kernel', __('Kernel')),
                    $this->entry('os.architecture', __('Architecture')),
                    $this->entry('os.hostname', __('Hostname'))->extraAttributes(['class' => 'privacy-sensitive']),
                    $this->entry('os.container', __('Docker container'))
                        ->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No')),
                    $this->entry('os.cpu_count', __('CPU cores')),
                    $this->entry('os.memory', __('Memory'))
                        ->state(fn (): ?string => $this->usage('os.memory_available', 'os.memory_total')),
                    $this->entry('os.disk_free', __('Disk free'))
                        ->formatStateUsing(fn (int $state): string => Number::fileSize($state, precision: 1)),
                    $this->entry('os.load_average', __('Load average')),
                    $this->entry('os.uptime_seconds', __('Uptime'))
                        ->formatStateUsing(fn (int $state): string => CarbonInterval::seconds($state)->cascade()->forHumans(short: true, parts: 3)),
                    $this->entry('os.ffmpeg_version', 'FFmpeg'),
                    $this->toggleEntry('os.comskip', 'Comskip', on: __('Installed'), off: __('Not installed')),
                ]),
            Section::make(__('Database'))
                ->icon('heroicon-o-circle-stack')
                ->compact()
                ->schema([
                    $this->entry('database.driver', __('Driver')),
                    $this->toggleEntry('database.connected', __('Status'), on: __('Connected'), off: __('Unavailable'), offColor: 'danger'),
                    $this->entry('database.server_version', __('Server version')),
                    $this->entry('database.database', __('Database')),
                    $this->entry('database.host', __('Host'))
                        ->extraAttributes(['class' => 'privacy-sensitive'])
                        ->visible(fn (): bool => $this->value('database.driver') !== 'sqlite'),
                    $this->placementEntry('database.placement', __('Location')),
                    $this->entry('database.size', __('Size'))
                        ->formatStateUsing(fn (int $state): string => Number::fileSize($state, precision: 1)),
                    ...array_map(
                        fn (TextEntry $entry): TextEntry => $entry->visible(fn (): bool => $this->value('database.embedded_postgres') !== null),
                        [
                            $this->entry('database.embedded_postgres.active_version', __('PostgreSQL version')),
                            $this->entry('database.embedded_postgres.upgrade_message', __('Last upgrade result'))
                                ->state(fn (): ?string => $this->postgresUpgradeSummary())
                                ->placeholder(__('No upgrade has run'))
                                ->color(fn (): string => ($this->value('database.embedded_postgres.upgrade_result') === 'failed' || $this->value('database.embedded_postgres.warning')) ? 'danger' : 'gray'),
                        ],
                    ),
                    // Kept out of the array_map above: its ->visible() call would
                    // be overwritten by the shared one applied to every entry in
                    // that list, showing this for every embedded-Postgres install
                    // regardless of whether a rollback copy actually exists.
                    $this->entry('database.embedded_postgres.retained_bytes', __('Rollback copy size'))
                        ->visible(fn (): bool => $this->value('database.embedded_postgres') !== null && (bool) $this->value('database.embedded_postgres.has_retained_copies'))
                        ->formatStateUsing(fn (int $state): string => Number::fileSize($state, precision: 1)),
                ]),
            Section::make(__('Cache & queue'))
                ->icon('heroicon-o-bolt')
                ->compact()
                ->schema([
                    $this->entry('cache.cache_driver', __('Cache driver')),
                    $this->entry('cache.queue_driver', __('Queue driver')),
                    $this->entry('cache.session_driver', __('Session driver')),
                    $this->entry('cache.broadcast_driver', __('Broadcast driver')),
                    ...array_map(
                        fn (TextEntry $entry): TextEntry => $entry->visible(fn (): bool => (bool) $this->value('cache.redis_used')),
                        [
                            $this->toggleEntry('cache.redis_connected', __('Redis'), on: __('Connected'), off: __('Unavailable'), offColor: 'danger'),
                            $this->entry('cache.redis_version', __('Redis version')),
                            $this->entry('cache.redis_memory', __('Redis memory')),
                            $this->entry('cache.redis_host', __('Redis host'))->extraAttributes(['class' => 'privacy-sensitive']),
                            $this->placementEntry('cache.redis_placement', __('Redis location')),
                        ],
                    ),
                ]),
            Section::make('m3u-proxy')
                ->icon('heroicon-o-arrows-right-left')
                ->compact()
                ->schema([
                    $this->entry('proxy.status', __('Status'))
                        ->state(fn (): string => match (true) {
                            ! $this->value('proxy.enabled') => 'disabled',
                            (bool) $this->value('proxy.reachable') => 'running',
                            default => 'stopped',
                        })
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            'disabled' => __('Disabled'),
                            'running' => __('Connected'),
                            default => __('Unavailable'),
                        })
                        ->color(fn (string $state): string => self::statusColor($state)),
                    ...array_map(
                        fn (TextEntry $entry): TextEntry => $entry->visible(fn (): bool => (bool) $this->value('proxy.enabled')),
                        [
                            $this->placementEntry('proxy.mode', __('Mode')),
                            $this->entry('proxy.version', __('Version')),
                            $this->entry('proxy.python_version', 'Python'),
                            $this->entry('proxy.ffmpeg_version', 'FFmpeg'),
                            $this->entry('proxy.streamlink_version', 'Streamlink')->placeholder(__('Disabled')),
                            $this->entry('proxy.ytdlp_version', 'yt-dlp')->placeholder(__('Disabled')),
                            $this->entry('proxy.hardware_acceleration', __('Hardware acceleration'))->placeholder(__('Disabled')),
                        ],
                    ),
                ]),
            Section::make(__('PHP extensions'))
                ->icon('heroicon-o-puzzle-piece')
                ->description(fn (): string => __(':count loaded', ['count' => count($this->value('php.extensions') ?? [])]))
                ->compact()
                ->collapsible()
                ->collapsed()
                ->schema([
                    TextEntry::make('php.extensions')
                        ->hiddenLabel()
                        ->state(fn (): array => $this->value('php.extensions') ?? [])
                        ->badge()
                        ->color('gray'),
                ]),
        ]);
    }

    /**
     * One entry per dashboard health check. The set of checks only changes
     * with config (Redis in use or not), so building the rows from the report
     * at schema time is safe; their state stays lazy.
     *
     * @return list<TextEntry>
     */
    protected function checkEntries(): array
    {
        return array_map(function (array $check): TextEntry {
            $current = fn (): array => collect($this->value('checks'))->firstWhere('key', $check['key']) ?? $check;

            return TextEntry::make('checks.'.$check['key'])
                ->label($check['label'])
                ->inlineLabel()
                ->state(fn (): string => $current()['detail'])
                ->icon(fn (): string => $current()['ok'] ? 'heroicon-s-check-circle' : 'heroicon-s-exclamation-triangle')
                ->iconColor(fn (): string => $current()['ok'] ? 'success' : 'danger');
        }, $this->value('checks') ?? []);
    }

    /**
     * @return list<TextEntry>
     */
    protected function serviceEntries(): array
    {
        $services = [
            'nginx' => 'NGINX',
            'php_fpm' => 'PHP-FPM',
            'queue' => __('Queue worker (Horizon)'),
            'websocket' => __('Websockets (Reverb)'),
            'scheduler' => __('Scheduler (cron)'),
            'postgresql' => 'PostgreSQL',
            'redis' => 'Redis',
            'm3u_proxy' => 'm3u-proxy',
            'network_broadcast' => __('Network broadcast worker'),
        ];

        $entries = [
            TextEntry::make('services.unavailable')
                ->hiddenLabel()
                ->state(__('Service status is only available when running in the m3u-editor Docker container.'))
                ->color('gray')
                ->visible(fn (): bool => $this->value('services') === null),
        ];

        foreach ($services as $key => $label) {
            $entries[] = $this->entry("services.{$key}", $label)
                ->badge()
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    'running' => __('Running'),
                    'stopped' => __('Stopped'),
                    'external' => __('External'),
                    default => __('Disabled'),
                })
                ->color(fn (string $state): string => self::statusColor($state))
                ->visible(fn (): bool => $this->value('services') !== null);
        }

        return $entries;
    }

    protected function entry(string $path, string $label): TextEntry
    {
        return TextEntry::make($path)
            ->label($label)
            ->inlineLabel()
            ->state(fn (): mixed => $this->value($path))
            ->placeholder(__('Unknown'));
    }

    protected function toggleEntry(string $path, string $label, ?string $on = null, ?string $off = null, bool $warnWhenOn = false, string $offColor = 'gray'): TextEntry
    {
        return $this->entry($path, $label)
            ->badge()
            ->formatStateUsing(fn (bool $state): string => $state ? ($on ?? __('Enabled')) : ($off ?? __('Disabled')))
            ->color(fn (bool $state): string => match (true) {
                $state => $warnWhenOn ? 'warning' : 'success',
                default => $offColor,
            });
    }

    protected function placementEntry(string $path, string $label): TextEntry
    {
        return $this->entry($path, $label)
            ->badge()
            ->formatStateUsing(fn (string $state): string => $state === 'external' ? __('External') : __('Embedded'))
            ->color(fn (string $state): string => $state === 'external' ? 'info' : 'gray');
    }

    protected static function statusColor(string $status): string
    {
        return match ($status) {
            'running' => 'success',
            'stopped' => 'danger',
            'external' => 'info',
            default => 'gray',
        };
    }

    /**
     * "<available> free of <total>" for a pair of byte counts in the report.
     */
    protected function usage(string $availablePath, string $totalPath): ?string
    {
        $available = $this->value($availablePath);
        $total = $this->value($totalPath);

        if ($available === null || ! $total) {
            return null;
        }

        return __(':free free of :total', [
            'free' => Number::fileSize($available, precision: 1),
            'total' => Number::fileSize($total, precision: 1),
        ]);
    }

    protected function postgresUpgradeSummary(): ?string
    {
        if ($warning = $this->value('database.embedded_postgres.warning')) {
            return $warning;
        }

        return match ($this->value('database.embedded_postgres.upgrade_result')) {
            'upgraded' => __('Upgraded from :from to :to', [
                'from' => $this->value('database.embedded_postgres.upgrade_from'),
                'to' => $this->value('database.embedded_postgres.upgrade_to'),
            ]),
            'rolled_back' => __('Rolled back from :from to :to', [
                'from' => $this->value('database.embedded_postgres.upgrade_from'),
                'to' => $this->value('database.embedded_postgres.upgrade_to'),
            ]),
            'failed' => __('Upgrade to :to failed: :message', [
                'to' => $this->value('database.embedded_postgres.upgrade_to'),
                'message' => (string) $this->value('database.embedded_postgres.upgrade_message'),
            ]),
            default => null,
        };
    }

    protected function value(string $path): mixed
    {
        $this->report ??= app(SystemHealthService::class)->report();

        return data_get($this->report, $path);
    }
}
