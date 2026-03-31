<x-filament-panels::page>
    @php
        /** @var \App\Models\ExtensionPluginRun $run */
        $run = $this->runRecord;
        $fmt = app(\App\Services\DateFormatService::class);
        $statusColors = [
            'completed' => 'bg-success-50 text-success-700 ring-success-200 dark:bg-success-950/40 dark:text-success-300 dark:ring-success-800',
            'failed' => 'bg-danger-50 text-danger-700 ring-danger-200 dark:bg-danger-950/40 dark:text-danger-300 dark:ring-danger-800',
            'running' => 'bg-warning-50 text-warning-700 ring-warning-200 dark:bg-warning-950/40 dark:text-warning-300 dark:ring-warning-800',
            'cancelled' => 'bg-gray-100 text-gray-700 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800',
            'stale' => 'bg-warning-50 text-warning-700 ring-warning-200 dark:bg-warning-950/40 dark:text-warning-300 dark:ring-warning-800',
        ];
        $statusClass = $statusColors[$run->status] ?? 'bg-gray-50 text-gray-700 ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800';
        $payload = json_encode($run->payload ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $result = json_encode($run->result ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $runState = json_encode($run->run_state ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        $latestMessage = $this->logs->last()?->message;
        $progress = (int) ($run->progress ?? 0);
        $totals = collect(data_get($run->result, 'data.totals', []))
            ->filter(fn($value) => is_scalar($value));
        $reportPath = $this->reportPath();
    @endphp

    <div class="space-y-6">
        <section
            class="overflow-hidden rounded-3xl border border-gray-200/80 bg-gradient-to-br from-white via-primary-50/20 to-white shadow-sm dark:border-gray-800 dark:from-gray-950 dark:via-primary-950/20 dark:to-gray-950">
            <div class="grid gap-6 px-6 py-6 lg:grid-cols-[minmax(0,1.2fr)_minmax(280px,0.8fr)] lg:px-8">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center gap-3">
                        <span
                            class="inline-flex items-center rounded-full px-3 py-1 text-sm font-medium ring-1 ring-inset {{ $statusClass }}">
                            {{ \Illuminate\Support\Str::headline($run->status) }}
                        </span>
                        @if($run->dry_run)
                            <span
                                class="inline-flex items-center rounded-full bg-primary-50 px-3 py-1 text-sm font-medium text-primary-700 ring-1 ring-inset ring-primary-200 dark:bg-primary-950/40 dark:text-primary-300 dark:ring-primary-800">
                                {{ __('Dry run') }}
                            </span>
                        @endif
                        <span
                            class="inline-flex items-center rounded-full bg-gray-100 px-3 py-1 text-sm font-medium text-gray-700 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:text-gray-300 dark:ring-gray-800">
                            {{ \Illuminate\Support\Str::headline($run->trigger) }}
                        </span>
                    </div>

                    <div>
                        <p
                            class="text-sm font-medium uppercase tracking-[0.24em] text-primary-600 dark:text-primary-300">
                            {{ __('Plugin run detail') }}</p>
                        <h2 class="mt-2 text-2xl font-semibold tracking-tight text-gray-950 dark:text-white">
                            {{ $run->plugin?->name ?? __('Unknown plugin') }}
                        </h2>
                        <p class="mt-2 max-w-2xl text-sm leading-6 text-gray-600 dark:text-gray-300">
                            {{ $run->summary ?: __('This run has no summary yet. Use the payload, result, and log stream below to inspect what happened.') }}
                        </p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-1">
                        <div
                            class="rounded-2xl border border-gray-200 bg-white/80 p-4 backdrop-blur dark:border-gray-800 dark:bg-gray-900/80">
                            <div
                                class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">
                                {{ __('Invocation') }}</div>
                            <div class="mt-2 text-sm font-medium text-gray-950 dark:text-white">
                                {{ $run->action ? \Illuminate\Support\Str::headline($run->action) : ($run->hook ? \Illuminate\Support\Str::headline($run->hook) : __('Plugin run')) }}
                            </div>
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ \Illuminate\Support\Str::headline($run->invocation_type) }}
                            </div>
                        </div>
                        <div
                            class="rounded-2xl border border-gray-200 bg-white/80 p-4 backdrop-blur dark:border-gray-800 dark:bg-gray-900/80">
                            <div
                                class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">
                                {{ __('Current signal') }}</div>
                            <div class="mt-2 text-sm font-medium text-gray-950 dark:text-white">
                                {{ $run->progress_message ?: ($latestMessage ?: __('No log messages yet')) }}
                            </div>
                            <div class="mt-3 h-2 overflow-hidden rounded-full bg-gray-100 dark:bg-gray-800">
                                <div class="h-full rounded-full bg-primary-500 transition-all"
                                    style="width: {{ max(2, $progress) }}%"></div>
                            </div>
                            <div class="mt-2 text-xs text-gray-500 dark:text-gray-400">{{ $progress }}% {{ __('recorded progress.') }}</div>
                        </div>
                        <div
                            class="rounded-2xl border border-gray-200 bg-white/80 p-4 backdrop-blur dark:border-gray-800 dark:bg-gray-900/80">
                            <div
                                class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">
                                {{ __('Queued by') }}</div>
                            <div class="mt-2 text-sm font-medium text-gray-950 dark:text-white">
                                {{ $run->user?->name ?? __('System') }}
                            </div>
                            <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                                {{ $fmt->format($run->created_at, 'Unknown time') }}
                            </div>
                        </div>
                    </div>
                </div>

                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-1">
                    <div
                        class="rounded-2xl border border-gray-200 bg-white/90 p-5 shadow-xs dark:border-gray-800 dark:bg-gray-900/90">
                        <div
                            class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">
                            {{ __('Lifecycle') }}</div>
                        <dl class="mt-4 space-y-3 text-sm text-gray-600 dark:text-gray-300">
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('Queued') }}</dt>
                                <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                                    {{ $fmt->format($run->created_at) }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('Started') }}</dt>
                                <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                                    {{ $run->started_at ? $fmt->format($run->started_at) : __('Not started') }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('Finished') }}</dt>
                                <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                                    {{ $run->finished_at ? $fmt->format($run->finished_at) : __('Still running') }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">{{ __('Last heartbeat') }}</dt>
                                <dd class="mt-1 font-medium text-gray-950 dark:text-white">
                                    {{ $run->last_heartbeat_at ? $fmt->format($run->last_heartbeat_at) : __('No heartbeat yet') }}
                                </dd>
                            </div>
                        </dl>
                    </div>

                    <div
                        class="rounded-2xl border border-gray-200 bg-white/90 p-5 shadow-xs dark:border-gray-800 dark:bg-gray-900/90">
                        <div
                            class="text-[11px] font-semibold uppercase tracking-[0.18em] text-gray-500 dark:text-gray-400">
                            {{ __('Returned totals') }}</div>
                        <div class="mt-4 flex flex-wrap gap-2 text-sm text-gray-600 dark:text-gray-300">
                            @forelse($totals as $key => $value)
                                <span
                                    class="inline-flex rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                                    {{ \Illuminate\Support\Str::headline((string) $key) }} · {{ $value }}
                                </span>
                            @empty
                                <span class="text-sm text-gray-500 dark:text-gray-400">{{ __('This run did not publish aggregate totals.') }}</span>
                            @endforelse
                        </div>

                        @if($reportPath)
                            <div
                                class="mt-4 rounded-2xl bg-gray-50 p-4 text-xs text-gray-500 dark:bg-gray-950/60 dark:text-gray-300">
                                <div class="font-semibold text-gray-700 dark:text-gray-100">{{ __('Artifact') }}</div>
                                <div class="mt-1">{{ $this->reportFilename() }}</div>
                                <div class="mt-1">{{ $reportPath }}</div>
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </section>

        <section class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
            <div
                class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                <div class="flex items-center justify-between gap-3">
                    <div>
                        <h2 class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Activity stream') }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ __('Latest persisted log messages for this run.') }}</p>
                    </div>
                </div>

                @if($this->logs->isNotEmpty())
                    <div class="mt-5 space-y-3">
                        @foreach($this->logs as $log)
                            <div
                                class="rounded-2xl border border-gray-200 bg-gray-50/70 p-4 dark:border-gray-800 dark:bg-gray-950/60">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span
                                        class="inline-flex rounded-full bg-gray-900 px-2.5 py-1 text-[11px] font-semibold uppercase tracking-[0.18em] text-white dark:bg-gray-100 dark:text-gray-900">
                                        {{ strtoupper($log->level ?? 'info') }}
                                    </span>
                                    <span
                                        class="text-xs text-gray-500 dark:text-gray-400">{{ $fmt->format($log->created_at) }}</span>
                                </div>
                                <p class="mt-3 text-sm text-gray-800 dark:text-gray-100">{{ $log->message }}</p>
                                @if(!empty($log->context))
                                    <pre
                                        class="mt-3 overflow-x-auto rounded-xl bg-white p-3 text-xs text-gray-700 dark:bg-gray-900 dark:text-gray-200">{{ json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @else
                    <div
                        class="mt-5 rounded-2xl border border-dashed border-gray-200 px-4 py-10 text-center text-sm text-gray-500 dark:border-gray-800 dark:text-gray-400">
                        No run logs have been recorded yet.
                    </div>
                @endif
            </div>

            <div class="space-y-6">
                <div
                    class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Payload</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">The exact input saved with this run.</p>
                    <pre
                        class="mt-4 overflow-x-auto rounded-2xl bg-gray-950 p-4 text-xs text-gray-100">{{ $payload ?: '{}' }}</pre>
                </div>

                <div
                    class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Result</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">The persisted result payload returned by
                        the plugin.</p>
                    <pre
                        class="mt-4 overflow-x-auto rounded-2xl bg-gray-950 p-4 text-xs text-gray-100">{{ $result ?: '{}' }}</pre>
                </div>

                <div
                    class="rounded-3xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-800 dark:bg-gray-900">
                    <h2 class="text-sm font-semibold text-gray-950 dark:text-white">Run state</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">Checkpoint data used for long-running or
                        resumed work.</p>
                    <pre
                        class="mt-4 overflow-x-auto rounded-2xl bg-gray-950 p-4 text-xs text-gray-100">{{ $runState ?: '{}' }}</pre>
                </div>
            </div>
        </section>
    </div>
</x-filament-panels::page>