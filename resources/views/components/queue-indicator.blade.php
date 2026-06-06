@php
    use App\Settings\GeneralSettings;
    use Illuminate\Support\Facades\Route;

    $endpoint = route('admin.queue-indicator');
    $user = auth()->user();
    $canViewQueueManager = false;

    try {
        $canViewQueueManager = (bool) $user?->isAdmin() && (bool) app(GeneralSettings::class)->show_queue_manager;
    } catch (\Throwable) {
        $canViewQueueManager = false;
    }

    $horizonUrl = url('/'.trim((string) config('horizon.path', 'horizon'), '/'));

    foreach (['horizon.index', 'horizon.dashboard', 'horizon'] as $routeName) {
        if (! Route::has($routeName)) {
            continue;
        }

        try {
            $horizonUrl = route($routeName);
            break;
        } catch (\Throwable) {
            // Intentionally ignore missing route names and continue to the next option.
        }
    }
@endphp

<div
    class="fi-queue-indicator relative flex items-center"
    x-data="{
        endpoint: @js($endpoint),
        open: false,
        loading: true,
        labels: {
            unknownJob: @js(__('Unknown job')),
            runningFallback: @js(__('Running')),
            waitingFallback: @js(__('Waiting')),
            defaultQueue: @js(__('Default')),
            since: @js(__('since')),
            queue: @js(__('Queue')),
            batch: @js(__('Batch')),
            approxRemaining: @js(__('Estimated remaining: :duration')),
        },
        snapshot: { running: 0, queued: 0, running_jobs: [], batches: [], upcoming: [], degraded: false, as_of: null },
        totalActivity() {
            return (this.snapshot.running || 0) + (this.snapshot.queued || 0);
        },
        hasActivity() {
            return this.totalActivity() > 0;
        },
        jobTitle(job) {
            return job.human_name || job.name || this.labels.unknownJob;
        },
        etaText(batch) {
            return this.labels.approxRemaining.replace(':duration', batch.eta_label || '');
        },
        async refresh() {
            try {
                const response = await fetch(this.endpoint, {
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin',
                });

                if (! response.ok) {
                    throw new Error('Queue indicator request failed');
                }

                this.snapshot = await response.json();
                this.snapshot.running_jobs ??= [];
                this.snapshot.batches ??= [];
                this.snapshot.upcoming ??= [];
            } catch (error) {
                this.snapshot = { running: 0, queued: 0, running_jobs: [], batches: [], upcoming: [], degraded: true, reason: 'request_failed', as_of: new Date().toISOString() };
            } finally {
                this.loading = false;
            }
        },
        init() {
            this.refresh();
            setInterval(() => this.refresh(), 10000);
        },
    }"
    x-on:keydown.escape.window="open = false"
>
    <button
        type="button"
        class="fi-icon-btn fi-size-md fi-color-gray fi-topbar-queue-indicator-btn relative"
        x-on:click="open = ! open"
        aria-label="{{ __('Queue status') }}"
        title="{{ __('Queue status') }}"
    >
        <x-heroicon-o-circle-stack class="fi-icon fi-size-lg" />

        <div class="fi-icon-btn-badge-ctn" x-cloak x-show="! loading && hasActivity()">
            <span class="fi-badge fi-size-xs" x-text="totalActivity()"></span>
        </div>
    </button>

    <div
        x-cloak
        x-show="open"
        x-transition.origin.top.right
        x-on:click.outside="open = false"
        class="absolute right-0 top-11 z-50 w-[28rem] max-w-[calc(100vw-2rem)] rounded-xl bg-white p-4 shadow-lg ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10"
    >
        <div class="mb-3 flex items-start justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-gray-950 dark:text-white">{{ __('Queue status') }}</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Clear overview of what is running and what is still waiting.') }}</p>
            </div>
            @if ($canViewQueueManager)
                <a href="{{ $horizonUrl }}" target="_blank" class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                    {{ __('Queue manager') }}
                </a>
            @endif
        </div>

        <template x-if="! hasActivity() && (snapshot.batches || []).length === 0 && ! snapshot.degraded">
            <p class="mb-3 rounded-lg bg-success-50 p-3 text-sm text-success-700 dark:bg-success-500/10 dark:text-success-300">
                {{ __('No background tasks are currently running.') }}
            </p>
        </template>

        <div class="grid grid-cols-3 gap-2 text-sm">
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Running') }}</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white" x-text="snapshot.running || 0"></p>
            </div>
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Waiting') }}</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white" x-text="snapshot.queued || 0"></p>
            </div>
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Batches') }}</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white" x-text="(snapshot.batches || []).length"></p>
            </div>
        </div>

        <template x-if="snapshot.degraded">
            <p class="mt-3 rounded-lg bg-warning-50 p-2 text-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-300">
                {{ __('Queue data is partially unavailable. The page remains usable.') }}
            </p>
        </template>

        <div class="mt-4">
            <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Running now') }}</p>
            <template x-if="(snapshot.running_jobs || []).length === 0">
                <p class="rounded-lg bg-gray-50 p-3 text-sm text-gray-500 dark:bg-white/5 dark:text-gray-400">{{ __('No individual job is running right now.') }}</p>
            </template>
            <div class="space-y-2">
                <template x-for="job in snapshot.running_jobs" x-bind:key="job.id || job.name">
                    <div class="rounded-lg border border-primary-200 bg-primary-50/50 p-3 text-xs dark:border-primary-500/20 dark:bg-primary-500/10">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-gray-950 dark:text-white" x-text="jobTitle(job)"></p>
                                <p class="mt-0.5 text-gray-600 dark:text-gray-300">
                                    <span x-text="job.status_label || labels.runningFallback"></span>
                                    <template x-if="job.age_label">
                                        <span> <span x-text="labels.since"></span> <span x-text="job.age_label"></span></span>
                                    </template>
                                </p>
                                <p class="mt-1 text-gray-500 dark:text-gray-400">
                                    <span x-text="labels.queue"></span>: <span x-text="job.queue || labels.defaultQueue"></span>
                                    <template x-if="job.batch_id">
                                        <span> · <span x-text="labels.batch"></span> <span x-text="job.batch_id.slice(0, 8)"></span></span>
                                    </template>
                                </p>
                                <template x-if="job.chunk_label">
                                    <p class="mt-1 text-gray-500 dark:text-gray-400" x-text="job.chunk_label"></p>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div class="mt-4">
            <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Active batches') }}</p>
            <template x-if="(snapshot.batches || []).length === 0">
                <p class="rounded-lg bg-gray-50 p-3 text-sm text-gray-500 dark:bg-white/5 dark:text-gray-400">{{ __('No active batches.') }}</p>
            </template>
            <div class="space-y-2">
                <template x-for="batch in snapshot.batches" x-bind:key="batch.id">
                    <div class="rounded-lg border border-gray-200 p-3 text-xs dark:border-white/10">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate font-medium text-gray-950 dark:text-white" x-text="batch.name || labels.batch"></p>
                                <p class="mt-0.5 text-gray-500 dark:text-gray-400">
                                    <span x-text="batch.status_label || labels.runningFallback"></span>
                                    · <span x-text="batch.processed || 0"></span>/<span x-text="batch.total || 0"></span> {{ __('done') }}
                                    · <span x-text="batch.pending || 0"></span> {{ __('open') }}
                                </p>
                                <template x-if="batch.eta_label">
                                    <p class="mt-1 text-gray-500 dark:text-gray-400" x-text="etaText(batch)"></p>
                                </template>
                                <template x-if="(batch.failed || 0) > 0">
                                    <p class="mt-1 text-danger-600 dark:text-danger-400"><span x-text="batch.failed"></span> {{ __('failed') }}.</p>
                                </template>
                            </div>
                            <span
                                class="rounded-full px-2 py-0.5 text-[0.65rem] font-semibold"
                                x-bind:class="(batch.failed || 0) > 0 ? 'bg-danger-100 text-danger-700 dark:bg-danger-500/10 dark:text-danger-300' : 'bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300'"
                                x-text="(batch.progress || 0) + '%'"
                            ></span>
                        </div>
                        <div class="mt-2 h-1.5 overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                            <div
                                class="h-full rounded-full bg-primary-600 dark:bg-primary-500"
                                x-bind:style="`width: ${Math.min(batch.progress || 0, 100)}%`"
                            ></div>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div class="mt-4">
            <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Waiting') }}</p>
            <template x-if="(snapshot.upcoming || []).length === 0">
                <p class="rounded-lg bg-gray-50 p-3 text-sm text-gray-500 dark:bg-white/5 dark:text-gray-400">{{ __('No waiting jobs.') }}</p>
            </template>
            <div class="space-y-2">
                <template x-for="job in snapshot.upcoming" x-bind:key="job.id || job.name">
                    <div class="rounded-lg border border-gray-200 p-3 text-xs dark:border-white/10">
                        <p class="truncate font-medium text-gray-950 dark:text-white" x-text="jobTitle(job)"></p>
                        <p class="mt-0.5 text-gray-500 dark:text-gray-400">
                            <span x-text="job.status_label || labels.waitingFallback"></span>
                            <template x-if="job.age_label">
                                <span> <span x-text="labels.since"></span> <span x-text="job.age_label"></span></span>
                            </template>
                            · <span x-text="labels.queue"></span>: <span x-text="job.queue || labels.defaultQueue"></span>
                            <template x-if="job.batch_id">
                                <span> · <span x-text="labels.batch"></span> <span x-text="job.batch_id.slice(0, 8)"></span></span>
                            </template>
                        </p>
                        <template x-if="job.chunk_label">
                            <p class="mt-1 text-gray-500 dark:text-gray-400" x-text="job.chunk_label"></p>
                        </template>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
