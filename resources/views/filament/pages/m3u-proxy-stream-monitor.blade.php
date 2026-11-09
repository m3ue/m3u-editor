<x-filament-panels::page>
    @php
        $intervalOptions = [0 => 'Off', 3 => '3s', 5 => '5s', 10 => '10s', 30 => '30s'];
    @endphp
    <div
        x-data="{
        intervalSeconds: (() => { const s = Number(localStorage.getItem('streamMonitor.refreshInterval')); return [0, 3, 5, 10, 30].includes(s) ? s : {{ $refreshInterval }}; })(),
        intervalId: null,
        showUrls: @js($privacyMode) || (() => { const v = localStorage.getItem('streamMonitor.showUrls'); return v === null ? true : v === '1'; })(),
        startPolling() {
            this.stopPolling();
            if (this.intervalSeconds <= 0) return;
            this.intervalId = setInterval(() => {
                if (document.visibilityState === 'visible') {
                    $wire.refreshData();
                }
            }, this.intervalSeconds * 1000);
        },
        stopPolling() {
            if (this.intervalId) {
                clearInterval(this.intervalId);
                this.intervalId = null;
            }
        },
        onVisibilityChange: null,
        // Alpine calls this when SPA navigation removes the page; there's no alpine:destroy event.
        destroy() {
            this.stopPolling();
            document.removeEventListener('visibilitychange', this.onVisibilityChange);
        }
    }"
        x-init="
            startPolling();
            $watch('intervalSeconds', (value) => {
                localStorage.setItem('streamMonitor.refreshInterval', value);
                startPolling();
            });
            $watch('showUrls', (value) => {
                localStorage.setItem('streamMonitor.showUrls', value ? '1' : '0');
            });
            onVisibilityChange = () => {
                if (document.visibilityState === 'visible' && intervalSeconds > 0) {
                    $wire.refreshData();
                }
            };
            document.addEventListener('visibilitychange', onVisibilityChange);
        "
        x-on:beforeunload.window="stopPolling()"
    >
        <!-- Global Statistics Cards -->
        <div class="mb-4 grid grid-cols-1 gap-3 md:grid-cols-4">
            <x-filament::card>
                <div class="flex items-center">
                    <div class="rounded-lg bg-blue-100 p-1.5 dark:bg-blue-900">
                        <x-heroicon-s-signal class="h-5 w-5" />
                    </div>
                    <div class="ml-3">
                        <p class="text-xs font-medium text-gray-600 dark:text-gray-400">Active Streams</p>
                        <p class="text-xl font-bold text-gray-900 dark:text-white">
                            {{ $globalStats['active_streams'] ?? 0 }}
                        </p>
                    </div>
                </div>
            </x-filament::card>

            <x-filament::card>
                <div class="flex items-center">
                    <div class="rounded-lg bg-green-100 p-1.5 dark:bg-green-900">
                        <x-heroicon-s-user-group class="h-5 w-5" />
                    </div>
                    <div class="ml-3">
                        <p class="text-xs font-medium text-gray-600 dark:text-gray-400">Total Clients</p>
                        <p class="text-xl font-bold text-gray-900 dark:text-white">
                            {{ $globalStats['total_clients'] ?? 0 }}
                        </p>
                    </div>
                </div>
            </x-filament::card>

            <x-filament::card>
                <div class="flex items-center">
                    <div class="rounded-lg bg-purple-100 p-1.5 dark:bg-purple-900">
                        <x-heroicon-s-bolt class="h-5 w-5" />
                    </div>
                    <div class="ml-3">
                        <p class="text-xs font-medium text-gray-600 dark:text-gray-400">Total Bandwidth</p>
                        <p class="text-xl font-bold text-gray-900 dark:text-white">
                            @php
                                $totalBandwidth = $globalStats['total_bandwidth_kbps'] ?? 0;
                                echo $totalBandwidth > 1000
                                    ? round($totalBandwidth / 1000, 1).' Mbps'
                                    : $totalBandwidth.' kbps';
                            @endphp
                        </p>
                    </div>
                </div>
            </x-filament::card>

            <x-filament::card>
                <div class="flex items-center">
                    <div class="rounded-lg bg-indigo-100 p-1.5 dark:bg-indigo-900">
                        <x-heroicon-s-chart-bar class="h-5 w-5" />
                    </div>
                    <div class="ml-3">
                        <p class="text-xs font-medium text-gray-600 dark:text-gray-400">Avg Clients/Stream</p>
                        <p class="text-xl font-bold text-gray-900 dark:text-white">
                            {{ $globalStats['avg_clients_per_stream'] ?? '0.00' }}
                        </p>
                    </div>
                </div>
            </x-filament::card>
        </div>

        <!-- Auto-refresh controls -->
        <div class="mb-3 flex items-center justify-between">
            <div class="flex items-center space-x-4">
                <label class="flex items-center space-x-2 text-sm text-gray-600 dark:text-gray-400">
                    <span>Auto-refresh</span>
                    <x-filament::input.wrapper>
                        <x-filament::input.select x-model.number="intervalSeconds" aria-label="Auto-refresh interval">
                            @foreach ($intervalOptions as $seconds => $label)
                                <option value="{{ $seconds }}">{{ $label }}</option>
                            @endforeach
                        </x-filament::input.select>
                    </x-filament::input.wrapper>
                </label>

                <x-filament::badge size="sm">
                    Last updated:
                    <span x-text="$wire.lastUpdatedAt ? new Date($wire.lastUpdatedAt).toLocaleTimeString() : '-'"></span>
                </x-filament::badge>
            </div>

            @unless ($privacyMode)
                <div class="flex items-center">
                    <x-filament::button
                        color="gray"
                        size="sm"
                        @click="showUrls = ! showUrls"
                        x-bind:icon="showUrls ? 'heroicon-o-eye-slash' : 'heroicon-o-eye'"
                        aria-label="{{ __('Toggle URL visibility') }}"
                    >
                        <span x-text="showUrls ? @js(__('Hide URLs')) : @js(__('Show URLs'))"></span>
                    </x-filament::button>
                </div>
            @endunless
        </div>

        <!-- Streams List -->
        @if ($connectionError)
            <x-filament::card class="p-8">
                <div class="flex items-start gap-4">
                    <div class="flex-shrink-0">
                        <div class="rounded-lg bg-red-100 p-3 dark:bg-red-900">
                            <x-heroicon-s-exclamation-triangle class="h-8 w-8 text-red-600 dark:text-red-300" />
                        </div>
                    </div>
                    <div class="flex-1">
                        <h3 class="mb-2 text-lg font-semibold text-red-900 dark:text-red-100">
                            Unable to Connect to <strong>m3u proxy</strong>
                        </h3>
                        <p class="mb-3 text-sm text-red-800 dark:text-red-200">{{ $connectionError }}</p>
                        <div class="text-sm text-red-700 dark:text-red-300">
                            <p class="mb-2 font-medium">Please verify:</p>
                            <ul class="ml-2 list-inside list-disc space-y-1">
                                <li>The m3u-proxy server is running</li>
                                <li>
                                    The proxy URL is configured correctly:
                                    <code class="privacy-sensitive rounded bg-red-200 px-1 py-0.5 text-xs dark:bg-red-800">{{ config('proxy.m3u_proxy_url') ?? url('/m3u-proxy') }}</code>
                                </li>
                                <li>There are no firewall or network issues blocking the connection</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </x-filament::card>
        @elseif (empty($streams))
            <x-filament::card class="p-8 text-center">
                <div class="text-gray-500 dark:text-gray-400">
                    <div class="mb-4 flex w-full items-center justify-center">
                        <x-heroicon-s-video-camera class="h-12 w-12" />
                    </div>
                    <p class="text-lg font-medium">No active streams</p>
                    <p class="text-sm">Streams will appear here when clients connect</p>
                </div>
            </x-filament::card>
        @else
            <div class="space-y-3">
                @foreach ($streams as $stream)
                    <x-filament::card>
                        <div x-data="{ showClients: false, showDetails: false }">
                            <!-- Stream Header -->
                            <div class="mb-3 items-center justify-between overflow-hidden md:flex">
                                <div class="items-center space-y-2 space-x-0 md:flex md:space-y-0 md:space-x-3">
                                    <div class="flex-shrink-0">
                                        @php
                                            $statusIconClass = match ($stream['status']) {
                                                'active' => 'bg-green-100 dark:bg-green-900 text-green-600 dark:text-green-300',
                                                'idle' => 'bg-blue-100 dark:bg-blue-900 text-blue-600 dark:text-blue-300',
                                                default => 'bg-red-100 dark:bg-red-900 text-red-600 dark:text-red-300',
                                            };
                                        @endphp
                                        <div class="h-8 w-8 rounded-full flex items-center justify-center {{ $statusIconClass }}">
                                            @if ($stream['status'] === 'idle')
                                                <x-heroicon-s-pause class="h-4 w-4" />
                                            @elseif ($stream['status'] === 'active')
                                                <x-heroicon-s-play class="h-4 w-4" />
                                            @else
                                                <x-heroicon-s-exclamation-triangle class="h-4 w-4" />
                                            @endif
                                        </div>
                                    </div>
                                    @if ($stream['model']['logo'] ?? false)
                                        <div class="shrink-0">
                                            <img
                                                src="{{ $stream['model']['logo'] }}"
                                                alt="Stream Thumbnail"
                                                class="h-8 w-auto rounded-md object-cover"
                                            />
                                        </div>
                                    @endif
                                    <div class="min-w-0 flex-1">
                                        <h3 class="text-base font-semibold text-gray-900 dark:text-white">
                                            Stream {{ substr($stream['stream_id'], -8) }}
                                        </h3>
                                        <p class="truncate font-mono text-xs text-gray-500 dark:text-gray-400">
                                            {{ $stream['model']['title'] ?? 'N/A' }}
                                            @if (! empty($stream['failover_channel']['title']))
                                                <span class="mx-1 text-gray-400 dark:text-gray-500">&rarr;</span>
                                                <span class="font-medium text-orange-600 dark:text-orange-400">{{ $stream['failover_channel']['title'] }}</span>
                                            @endif
                                        </p>
                                        <p
                                            class="privacy-sensitive truncate font-mono text-xs text-gray-500 transition-[filter] duration-150 dark:text-gray-400"
                                            :class="{ 'blur-xs select-none': ! showUrls }"
                                        >
                                            {{ $stream['source_url'] }}
                                        </p>
                                    </div>
                                </div>
                            </div>

                            <!-- Stream Badges -->
                            <div class="mb-3 flex flex-wrap items-center gap-1.5">
                                @if ($stream['alias_name'] ?? false)
                                    <x-filament::badge color="primary" size="sm">
                                        Alias: {{ $stream['alias_name'] }}
                                    </x-filament::badge>
                                @endif
                                @if ($stream['playlist_name'] ?? false)
                                    <x-filament::badge color="primary" size="sm">
                                        @if ($stream['profiles_enabled'] && ($stream['provider_profile'] ?? false))
                                            {{ $stream['playlist_name'] }}: {{ $stream['provider_profile'] }}
                                        @else
                                            {{ $stream['playlist_name'] }}
                                        @endif
                                    </x-filament::badge>
                                @elseif ($stream['provider_profile'] ?? false)
                                    <x-filament::badge color="primary" size="sm">
                                        {{ $stream['provider_profile'] }}
                                    </x-filament::badge>
                                @endif
                                <x-filament::badge color="info" size="sm"> {{ $stream['format'] }} </x-filament::badge>
                                @if ($stream['broadcast'] ?? false)
                                    @if ($stream['is_dvr'] ?? false)
                                        <x-filament::badge color="danger" size="sm" icon="heroicon-s-video-camera">
                                            DVR Recording
                                        </x-filament::badge>
                                    @else
                                        <x-filament::badge color="info" size="sm" icon="heroicon-s-signal">
                                            Broadcast
                                        </x-filament::badge>
                                    @endif
                                @endif
                                @if ($stream['transcoding'])
                                    <x-filament::badge color="info" size="sm">
                                        {{ $stream['transcoding_format'] ?? 'N/A' }}
                                    </x-filament::badge>
                                @endif
                                @if ($stream['transcoding_backend'] ?? false)
                                    <x-filament::badge color="success" size="sm">
                                        {{ $stream['transcoding_backend'] }}
                                    </x-filament::badge>
                                @endif
                                @if ($stream['using_failover'])
                                    <x-filament::badge color="warning" size="sm" icon="heroicon-s-arrow-path">
                                        Failover Active
                                    </x-filament::badge>
                                @elseif ($stream['has_failover'])
                                    <x-filament::badge color="gray" size="sm" icon="heroicon-s-shield-check">
                                        Failover Ready
                                    </x-filament::badge>
                                @endif
                                @php
                                    $statusBadgeColor = match ($stream['status']) {
                                        'active' => 'success',
                                        'idle' => 'info',
                                        default => 'danger',
                                    };
                                @endphp
                                <x-filament::badge :color="$statusBadgeColor" size="sm">
                                    {{ ucfirst($stream['status']) }}
                                </x-filament::badge>
                            </div>

                            <!-- Stream Stats Grid -->
                            <div class="mb-3 grid grid-cols-2 gap-2 md:grid-cols-4">
                                <div class="rounded-lg bg-gray-50 p-2 dark:bg-gray-800">
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Clients</div>
                                    <div class="text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ $stream['client_count'] }}
                                    </div>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-2 dark:bg-gray-800">
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Bandwidth</div>
                                    <div class="text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ $stream['bandwidth_kbps'] > 1000 ? round($stream['bandwidth_kbps'] / 1000, 1) . ' Mbps' : $stream['bandwidth_kbps'] . ' kbps' }}
                                    </div>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-2 dark:bg-gray-800">
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Data Transferred</div>
                                    <div class="text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ $stream['bytes_transferred'] }}
                                    </div>
                                </div>
                                <div class="rounded-lg bg-gray-50 p-2 dark:bg-gray-800">
                                    <div class="text-xs text-gray-500 dark:text-gray-400">Uptime</div>
                                    <div class="text-sm font-semibold text-gray-900 dark:text-white">
                                        {{ $stream['uptime'] }}
                                    </div>
                                </div>
                            </div>

                            @php
                                $epg = $stream['epg'] ?? null;
                                $epgCurrent = $epg['current'] ?? null;
                                $epgNext = $epg['next'] ?? null;
                                $epgProgress = $epgCurrent ? (int) round(($epgCurrent['progress'] ?? 0) * 100) : 0;
                                $epgTz = config('app.timezone');
                                $epgStartLabel = null;
                                $epgStopLabel = null;
                                if ($epgCurrent) {
                                    try {
                                        $epgStartLabel = \Illuminate\Support\Carbon::parse($epgCurrent['start'])->setTimezone($epgTz)->format('g:i A');
                                        $epgStopLabel = \Illuminate\Support\Carbon::parse($epgCurrent['stop'])->setTimezone($epgTz)->format('g:i A');
                                    } catch (\Throwable $e) {
                                        // ignore parse errors; fall back to no label
                                    }
                                }
                            @endphp
                            @if ($epgCurrent)
                                <!-- Current Programme + Up Next (mirrors the m3u-tv player overlay) -->
                                <div class="mb-3 rounded-lg border border-gray-200 bg-gray-50 p-2 dark:border-gray-700 dark:bg-gray-800/60">
                                    <div class="mb-1.5 flex flex-wrap items-center gap-1.5">
                                        <x-filament::badge color="danger" size="sm" icon="heroicon-s-signal">
                                            Live
                                        </x-filament::badge>
                                        <span class="truncate text-sm font-semibold text-gray-900 dark:text-white">
                                            {{ $epgCurrent['title'] }}
                                        </span>
                                        @if ($epgStartLabel && $epgStopLabel)
                                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ $epgStartLabel }} - {{ $epgStopLabel }}
                                            </span>
                                        @endif
                                        <span class="ml-auto text-xs text-gray-500 dark:text-gray-400">
                                            {{ $epgProgress }}%
                                        </span>
                                    </div>
                                    <div class="h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                                        <div
                                            class="bg-primary-500 dark:bg-primary-400 h-full rounded-full transition-[width] duration-500"
                                            style="width: {{ $epgProgress }}%;"
                                        ></div>
                                    </div>
                                    @if ($epgNext)
                                        <p class="mt-2 truncate text-xs text-gray-500 dark:text-gray-400">
                                            <span class="font-medium text-gray-600 dark:text-gray-300">Next:</span>
                                            {{ $epgNext['title'] }}
                                            @if (! empty($epgNext['start']))
                                                @php
                                                    try {
                                                        $nextStartLabel = \Illuminate\Support\Carbon::parse($epgNext['start'])->setTimezone($epgTz)->format('g:i A');
                                                    } catch (\Throwable $e) {
                                                        $nextStartLabel = null;
                                                    }
                                                @endphp
                                                @if ($nextStartLabel)
                                                    <span class="text-gray-400 dark:text-gray-500">
                                                        · {{ $nextStartLabel }}
                                                    </span>
                                                @endif
                                            @endif
                                        </p>
                                    @endif
                                </div>
                            @endif

                            @php
                                $mediaInfo = $stream['model']['media_info'] ?? null;
                                $outputMediaInfo = $stream['model']['output_media_info'] ?? null;
                            @endphp
                            @if ($mediaInfo || $outputMediaInfo)
                                <!-- Stream stats: input row always shown when present, output row added when transcoding -->
                                <div class="mb-3 divide-y divide-gray-200 overflow-hidden rounded-lg border border-gray-200 dark:divide-gray-700 dark:border-gray-700">
                                    @if ($mediaInfo)
                                        <div class="flex flex-wrap items-center gap-1.5 bg-gray-50 p-2 dark:bg-gray-800/60">
                                            <div class="mr-1 flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                                <x-heroicon-s-arrow-down-tray class="h-3.5 w-3.5" />
                                                <span class="font-medium tracking-wide uppercase">Input</span>
                                                @if ($mediaInfo['is_live'] ?? false)
                                                    <span
                                                        class="ml-1 inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-green-500"
                                                        title="Live data from active ffmpeg"
                                                    ></span>
                                                @endif
                                            </div>
                                            @if ($mediaInfo['resolution'] ?? false)
                                                <x-filament::badge color="info" size="sm" icon="heroicon-s-squares-2x2">
                                                    {{ $mediaInfo['resolution'] }}
                                                </x-filament::badge>
                                            @endif
                                            @if ($mediaInfo['video_codec'] ?? false)
                                                @php
                                                    $codecDisplay = strtoupper($mediaInfo['video_codec']);
                                                    if ($mediaInfo['video_profile'] ?? false) {
                                                        $codecDisplay .= ' · '.$mediaInfo['video_profile'];
                                                    }
                                                @endphp
                                                <x-filament::badge color="primary" size="sm" icon="heroicon-s-cpu-chip">
                                                    {{ $codecDisplay }}
                                                </x-filament::badge>
                                            @endif
                                            @if ($mediaInfo['source_fps'] ?? false)
                                                <x-filament::badge color="gray" size="sm">
                                                    {{ $mediaInfo['source_fps'] }} fps
                                                </x-filament::badge>
                                            @endif
                                            @if ($mediaInfo['video_bitrate_kbps'] ?? false)
                                                <x-filament::badge color="gray" size="sm">
                                                    {{ $mediaInfo['video_bitrate_kbps'] >= 1000 ? round($mediaInfo['video_bitrate_kbps'] / 1000, 1) . ' Mbps' : $mediaInfo['video_bitrate_kbps'] . ' kbps' }}
                                                </x-filament::badge>
                                            @endif
                                            @if ($mediaInfo['audio_codec'] ?? false)
                                                @php
                                                    $audioDisplay = strtoupper($mediaInfo['audio_codec']);
                                                    if ($mediaInfo['audio_channels'] ?? false) {
                                                        $audioDisplay .= ' · '.$mediaInfo['audio_channels'];
                                                    }
                                                    if ($mediaInfo['audio_language'] ?? false) {
                                                        $audioDisplay .=
                                                            ' ['.strtoupper($mediaInfo['audio_language']).']';
                                                    }
                                                @endphp
                                                <x-filament::badge
                                                    color="success"
                                                    size="sm"
                                                    icon="heroicon-s-speaker-wave"
                                                >
                                                    {{ $audioDisplay }}
                                                </x-filament::badge>
                                            @endif
                                            @if ($mediaInfo['audio_bitrate_kbps'] ?? false)
                                                <x-filament::badge color="gray" size="sm">
                                                    {{ $mediaInfo['audio_bitrate_kbps'] }} kbps audio
                                                </x-filament::badge>
                                            @endif
                                        </div>
                                    @endif
                                    @if ($outputMediaInfo)
                                        <div class="flex flex-wrap items-center gap-1.5 bg-gray-50 p-2 dark:bg-gray-800/60">
                                            <div class="mr-1 flex items-center gap-1 text-xs text-gray-500 dark:text-gray-400">
                                                <x-heroicon-s-arrow-up-tray class="h-3.5 w-3.5" />
                                                <span class="font-medium tracking-wide uppercase">Output</span>
                                                <span
                                                    class="ml-1 inline-block h-1.5 w-1.5 animate-pulse rounded-full bg-green-500"
                                                    title="Live data from active ffmpeg encoder"
                                                ></span>
                                            </div>
                                            @if ($outputMediaInfo['resolution'] ?? false)
                                                <x-filament::badge color="info" size="sm" icon="heroicon-s-squares-2x2">
                                                    {{ $outputMediaInfo['resolution'] }}
                                                </x-filament::badge>
                                            @endif
                                            @if ($outputMediaInfo['video_codec'] ?? false)
                                                <x-filament::badge color="primary" size="sm" icon="heroicon-s-cpu-chip">
                                                    {{ strtoupper($outputMediaInfo['video_codec']) }}
                                                </x-filament::badge>
                                            @endif
                                            @if ($outputMediaInfo['fps'] ?? false)
                                                <x-filament::badge color="gray" size="sm">
                                                    {{ $outputMediaInfo['fps'] }} fps
                                                </x-filament::badge>
                                            @endif
                                            @if ($outputMediaInfo['bitrate_kbps'] ?? false)
                                                <x-filament::badge color="gray" size="sm">
                                                    {{ $outputMediaInfo['bitrate_kbps'] >= 1000 ? round($outputMediaInfo['bitrate_kbps'] / 1000, 1) . ' Mbps' : $outputMediaInfo['bitrate_kbps'] . ' kbps' }}
                                                </x-filament::badge>
                                            @endif
                                            @if ($outputMediaInfo['audio_codec'] ?? false)
                                                @php
                                                    $outAudioDisplay = strtoupper($outputMediaInfo['audio_codec']);
                                                    if ($outputMediaInfo['audio_channels'] ?? false) {
                                                        $outAudioDisplay .= ' · '.$outputMediaInfo['audio_channels'];
                                                    }
                                                @endphp
                                                <x-filament::badge
                                                    color="success"
                                                    size="sm"
                                                    icon="heroicon-s-speaker-wave"
                                                >
                                                    {{ $outAudioDisplay }}
                                                </x-filament::badge>
                                            @endif
                                            @if ($outputMediaInfo['container'] ?? false)
                                                <x-filament::badge color="gray" size="sm">
                                                    {{ $outputMediaInfo['container'] }}
                                                </x-filament::badge>
                                            @endif
                                            @if ($outputMediaInfo['speed'] ?? false)
                                                <x-filament::badge
                                                    color="{{ $outputMediaInfo['speed'] >= 1.0 ? 'success' : 'warning' }}"
                                                    size="sm"
                                                    icon="heroicon-s-bolt"
                                                >
                                                    {{ number_format($outputMediaInfo['speed'], 2) }}×
                                                </x-filament::badge>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @endif

                            <!-- Action Buttons -->
                            <div class="items-center justify-between space-y-2 md:flex md:space-y-0">
                                <div class="flex items-center space-x-2">
                                    <x-filament::button color="gray" size="sm" @click="showClients = ! showClients">
                                        <span x-text="showClients ? 'Hide Clients' : 'Show Clients ({{ $stream['client_count'] }})'"></span>
                                    </x-filament::button>
                                    <x-filament::button color="gray" size="sm" @click="showDetails = ! showDetails">
                                        <span x-text="showDetails ? 'Hide Details' : 'Show Details'"></span>
                                    </x-filament::button>
                                </div>

                                <div class="flex items-center space-x-2">
                                    @unless ($stream['broadcast'] ?? false)
                                        <x-filament::button
                                            color="warning"
                                            size="sm"
                                            icon="heroicon-o-exclamation-triangle"
                                            @click="$wire.mountAction('triggerFailover', { streamId: '{{ $stream['stream_id'] }}' })"
                                            wire:loading.attr="disabled"
                                        >
                                            Trigger Failover
                                        </x-filament::button>
                                    @endunless
                                    <x-filament::button
                                        color="danger"
                                        size="sm"
                                        icon="heroicon-o-trash"
                                        @click="$wire.mountAction('stopStream', { streamId: '{{ $stream['stream_id'] }}' })"
                                        wire:loading.attr="disabled"
                                    >
                                        Remove Stream
                                    </x-filament::button>
                                </div>
                            </div>

                            <!-- Clients List -->
                            <div
                                x-show="showClients"
                                x-transition
                                class="mt-4 border-t pt-4 dark:border-gray-700"
                                style="display: none"
                            >
                                @if (empty($stream['clients']))
                                    <p class="text-sm text-gray-500 dark:text-gray-400">No active clients</p>
                                @else
                                    <div class="overflow-x-auto">
                                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                            <thead class="bg-gray-50 dark:bg-gray-800">
                                                <tr>
                                                    <th class="px-3 py-2 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                                        Client IP
                                                    </th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                                        Username
                                                    </th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                                        User Agent
                                                    </th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                                        Connected
                                                    </th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                                        Duration
                                                    </th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                                        Data
                                                    </th>
                                                    <th class="px-3 py-2 text-left text-xs font-medium tracking-wider text-gray-500 uppercase dark:text-gray-400">
                                                        Status
                                                    </th>
                                                </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-200 bg-white dark:divide-gray-700 dark:bg-gray-900">
                                                @foreach ($stream['clients'] as $client)
                                                    <tr>
                                                        <td class="px-3 py-2 font-mono text-sm whitespace-nowrap text-gray-900 dark:text-white">
                                                            {{ $client['ip'] }}
                                                        </td>
                                                        <td class="px-3 py-2 text-sm whitespace-nowrap text-gray-900 dark:text-white">
                                                            {{ $client['username'] ?? '-' }}
                                                        </td>
                                                        <td
                                                            class="max-w-xs truncate px-3 py-2 text-xs text-gray-500 dark:text-gray-400"
                                                            title="{{ $client['user_agent'] ?? '' }}"
                                                        >
                                                            {{ $client['user_agent'] ? \Illuminate\Support\Str::limit($client['user_agent'], 40, '…') : '-' }}
                                                        </td>
                                                        <td class="px-3 py-2 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400">
                                                            {{ $client['connected_at'] }}
                                                        </td>
                                                        <td class="px-3 py-2 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400">
                                                            {{ $client['duration'] }}
                                                        </td>
                                                        <td class="px-3 py-2 text-sm whitespace-nowrap text-gray-500 dark:text-gray-400">
                                                            {{ $client['bytes_received'] }}
                                                        </td>
                                                        <td class="px-3 py-2 whitespace-nowrap">
                                                            <x-filament::badge
                                                                :color="$client['is_active'] ? 'success' : 'danger'"
                                                                size="sm"
                                                            >
                                                                {{ $client['is_active'] ? 'Active' : 'Inactive' }}
                                                            </x-filament::badge>
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    </div>
                                @endif
                            </div>

                            <!-- Stream Details -->
                            <div
                                x-show="showDetails"
                                x-transition
                                class="mt-4 border-t pt-4 dark:border-gray-700"
                                style="display: none"
                            >
                                <div class="grid grid-cols-2 gap-4 text-sm md:grid-cols-3">
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Stream ID:</span>
                                        <div class="font-mono text-xs break-all text-gray-900 dark:text-gray-100">
                                            {{ $stream['stream_id'] }}
                                        </div>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Started At:</span>
                                        <div class="font-medium text-gray-900 dark:text-gray-100">
                                            {{ $stream['started_at'] ?? 'N/A' }}
                                        </div>
                                    </div>
                                    <div>
                                        <span class="text-gray-500 dark:text-gray-400">Process Status:</span>
                                        <div class="mt-1 font-medium">
                                            <x-filament::badge
                                                :color="$stream['process_running'] ? 'success' : 'gray'"
                                                size="sm"
                                            >
                                                {{ $stream['process_running'] ? 'Running' : 'Idle' }}
                                            </x-filament::badge>
                                        </div>
                                    </div>
                                </div>

                                @if ($stream['has_failover'])
                                    <!-- Failover Information Section -->
                                    <div class="mt-4 border-t pt-4 dark:border-gray-700">
                                        <h4 class="mb-3 flex items-center text-sm font-semibold text-gray-900 dark:text-white">
                                            <x-heroicon-s-arrow-path class="w-4 h-4 mr-2 {{ $stream['using_failover'] ? 'text-orange-500' : 'text-gray-400' }}" />
                                            Failover Status
                                        </h4>
                                        <div class="grid grid-cols-2 gap-4 md:grid-cols-4">
                                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                                    Failover Attempts
                                                </div>
                                                <div class="text-lg font-semibold {{ $stream['failover_attempts'] > 0 ? 'text-orange-600 dark:text-orange-400' : 'text-gray-900 dark:text-white' }}">
                                                    {{ $stream['failover_attempts'] }}
                                                </div>
                                            </div>
                                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                                    Current Source
                                                </div>
                                                <div class="text-lg font-semibold text-gray-900 dark:text-white">
                                                    @if ($stream['failover_resolver_url'])
                                                        Dynamic
                                                    @elseif ($stream['current_failover_index'] > 0)
                                                        Backup #{{ $stream['current_failover_index'] }}
                                                    @else
                                                        Primary
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                                    Available Backups
                                                </div>
                                                <div class="text-lg font-semibold text-gray-900 dark:text-white">
                                                    @if ($stream['failover_resolver_url'])
                                                        <span class="text-sm">Dynamic Resolver</span>
                                                    @else
                                                        {{ count($stream['failover_urls']) }}
                                                    @endif
                                                </div>
                                            </div>
                                            <div class="rounded-lg bg-gray-50 p-3 dark:bg-gray-800">
                                                <div class="text-xs text-gray-500 dark:text-gray-400">
                                                    Last Failover
                                                </div>
                                                <div class="text-sm font-semibold text-gray-900 dark:text-white">
                                                    {{ $stream['last_failover_time'] ?? 'Never' }}
                                                </div>
                                            </div>
                                        </div>

                                        @if ($stream['using_failover'])
                                            <div class="mt-3 rounded-lg border border-orange-200 bg-orange-50 p-3 dark:border-orange-800 dark:bg-orange-900/20">
                                                <div class="flex items-start">
                                                    <x-heroicon-s-exclamation-triangle class="mt-0.5 mr-2 h-5 w-5 flex-shrink-0 text-orange-500" />
                                                    <div>
                                                        <p class="text-sm font-medium text-orange-800 dark:text-orange-200">
                                                            Stream is using a failover source
                                                        </p>
                                                        <p class="mt-1 text-xs text-orange-600 dark:text-orange-300">
                                                            Original URL:
                                                            <span
                                                                class="privacy-sensitive font-mono break-all transition-[filter] duration-150"
                                                                :class="{ 'blur-sm select-none': ! showUrls }"
                                                            >{{ $stream['source_url'] }}</span>
                                                        </p>
                                                        @if ($stream['current_url'] && $stream['current_url'] !== $stream['source_url'])
                                                            <p class="text-xs text-orange-600 dark:text-orange-300">
                                                                Current URL:
                                                                <span
                                                                    class="privacy-sensitive font-mono break-all transition-[filter] duration-150"
                                                                    :class="{ 'blur-sm select-none': ! showUrls }"
                                                                >{{ $stream['current_url'] }}</span>
                                                            </p>
                                                        @endif
                                                    </div>
                                                </div>
                                            </div>
                                        @endif

                                        @if (! empty($stream['failover_urls']))
                                            <div class="mt-3">
                                                <details class="text-sm">
                                                    <summary class="cursor-pointer text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-white">
                                                        Show failover URLs ({{ count($stream['failover_urls']) }})
                                                    </summary>
                                                    <ul class="mt-2 ml-4 space-y-1">
                                                        @foreach ($stream['failover_urls'] as $index => $url)
                                                            <li class="flex items-center gap-2 font-mono text-xs text-gray-500 dark:text-gray-400">
                                                                <x-filament::badge
                                                                    :color="$stream['current_failover_index'] ===
                                                                $index + 1
                                                                    ? 'warning'
                                                                    : 'gray'"
                                                                    size="sm"
                                                                >
                                                                    {{ $index + 1 }}
                                                                </x-filament::badge>
                                                                <span
                                                                    class="transition-[filter] duration-150 privacy-sensitive {{ $stream['current_failover_index'] === $index + 1 ? 'text-orange-600 dark:text-orange-400 font-medium' : '' }}"
                                                                    :class="{ 'blur-sm select-none': ! showUrls }"
                                                                >
                                                                    {{ $url }}
                                                                </span>
                                                                @if ($stream['current_failover_index'] === $index + 1)
                                                                    <x-filament::badge color="warning" size="sm"
                                                                        >active</x-filament::badge>
                                                                @endif
                                                            </li>
                                                        @endforeach
                                                    </ul>
                                                </details>
                                            </div>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        </div>
                    </x-filament::card>
                @endforeach
            </div>
        @endif
    </div>
</x-filament-panels::page>
