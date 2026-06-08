@php
    $endpoint = route('admin.queue-indicator');
@endphp

<div class="fi-queue-indicator relative flex items-center" x-data="{
    open: false,
    loading: true,
    snapshot: { running: 0, queued: 0, running_jobs: [], batches: [], upcoming: [], degraded: false },
    get totalActivity() {
        return (this.snapshot.running ?? 0) + (this.snapshot.queued ?? 0);
    },
    get hasActivity() {
        return this.totalActivity > 0;
    },
    async refresh() {
        try {
            const res = await fetch(@js($endpoint), {
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
            });
            if (!res.ok) throw new Error();
            const data = await res.json();
            this.snapshot = {
                running: data.running ?? 0,
                queued: data.queued ?? 0,
                running_jobs: data.running_jobs ?? [],
                batches: data.batches ?? [],
                upcoming: data.upcoming ?? [],
                degraded: data.degraded ?? false,
            };
        } catch {
            this.snapshot = { running: 0, queued: 0, running_jobs: [], batches: [], upcoming: [], degraded: true };
        } finally {
            this.loading = false;
        }
    },
    init() {
        this.refresh();
        setInterval(() => this.refresh(), 5000);
    },
}" x-cloak>
    {{-- Trigger button --}}
    <button type="button" @click="open = !open"
        class="relative flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm font-medium transition hover:bg-gray-100 dark:hover:bg-white/5"
        :class="snapshot.degraded ? 'text-gray-400 dark:text-gray-500' : (hasActivity ?
            'text-primary-600 dark:text-primary-400' : 'text-gray-500 dark:text-gray-400')"
        :title="hasActivity ? `${snapshot.running} running, ${snapshot.queued} queued` : '{{ __('Queue idle') }}'">
        {{-- Spinner when running, otherwise queue icon --}}
        <template x-if="snapshot.running > 0 && !loading">
            <svg class="h-5 w-5 animate-spin" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                </circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
            </svg>
        </template>
        <template x-if="snapshot.running === 0 || loading">
            <x-heroicon-o-circle-stack class="h-5 w-5" />
        </template>

        <template x-if="hasActivity">
            <span x-text="totalActivity" class="tabular-nums"></span>
        </template>
    </button>

    {{-- Dropdown panel --}}
    <div x-show="open" @click.outside="open = false" x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95" x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75" x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        class="absolute right-0 top-full z-50 mt-1 w-80 origin-top-right rounded-xl border border-gray-200 bg-white shadow-lg dark:border-white/10 dark:bg-gray-900">
        {{-- Header --}}
        <div class="flex items-center justify-between border-b border-gray-100 px-4 py-2.5 dark:border-white/10">
            <span
                class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ __('Queue') }}</span>
            <div class="flex items-center gap-3 text-xs text-gray-500 dark:text-gray-400">
                <span>
                    <span class="font-medium text-gray-700 dark:text-gray-300" x-text="snapshot.running"></span>
                    {{ __('running') }}
                </span>
                <span>
                    <span class="font-medium text-gray-700 dark:text-gray-300" x-text="snapshot.queued"></span>
                    {{ __('queued') }}
                </span>
            </div>
        </div>

        <div class="max-h-96 overflow-y-auto">

            {{-- Active batches --}}
            <template x-if="snapshot.batches.length > 0">
                <div class="border-b border-gray-100 px-4 py-2 dark:border-white/10">
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                        {{ __('Batches') }}</p>
                    <template x-for="batch in snapshot.batches" :key="batch.id">
                        <div class="mb-2 last:mb-0">
                            <div class="flex items-center justify-between text-xs">
                                <span class="truncate font-medium text-gray-700 dark:text-gray-300"
                                    x-text="batch.name || 'Batch #' + batch.id.substring(0, 8)"></span>
                                <span class="ml-2 shrink-0 text-gray-400 dark:text-gray-500"
                                    x-text="batch.progress + '%'"></span>
                            </div>
                            <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-white/10">
                                <div class="h-full rounded-full transition-all duration-500"
                                    :class="batch.status === 'failing' ? 'bg-danger-500' : 'bg-primary-500'"
                                    :style="'width: ' + batch.progress + '%'"></div>
                            </div>
                            <div
                                class="mt-0.5 flex items-center justify-between text-xs text-gray-400 dark:text-gray-500">
                                <span x-text="batch.processed + ' / ' + batch.total + ' {{ __('jobs') }}'"></span>
                                <template x-if="batch.eta_label">
                                    <span>≈ <span x-text="batch.eta_label"></span> {{ __('left') }}</span>
                                </template>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Running jobs --}}
            <template x-if="snapshot.running_jobs.length > 0">
                <div class="border-b border-gray-100 px-4 py-2 dark:border-white/10">
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                        {{ __('Running') }}</p>
                    <template x-for="job in snapshot.running_jobs" :key="job.id">
                        <div class="flex items-center justify-between py-0.5 text-xs">
                            <div class="flex min-w-0 items-center gap-1.5">
                                <svg class="h-3 w-3 shrink-0 animate-spin text-primary-500"
                                    xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10"
                                        stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                </svg>
                                <span class="truncate font-medium text-gray-700 dark:text-gray-300"
                                    x-text="job.human_name || job.name || '{{ __('Unknown job') }}'"></span>
                            </div>
                            <div class="ml-2 flex shrink-0 items-center gap-2 text-gray-400 dark:text-gray-500">
                                <template x-if="job.chunk">
                                    <span x-text="job.chunk.current + ' / ' + job.chunk.total"></span>
                                </template>
                                <span class="rounded bg-gray-100 px-1 dark:bg-white/10"
                                    x-text="job.queue || 'default'"></span>
                            </div>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Upcoming / pending --}}
            <template x-if="snapshot.upcoming.length > 0">
                <div class="px-4 py-2">
                    <p class="mb-1.5 text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
                        {{ __('Pending') }}</p>
                    <template x-for="job in snapshot.upcoming" :key="job.id">
                        <div class="flex items-center justify-between py-0.5 text-xs">
                            <span class="truncate text-gray-600 dark:text-gray-400"
                                x-text="job.human_name || job.name || '{{ __('Unknown job') }}'"></span>
                            <span
                                class="ml-2 shrink-0 rounded bg-gray-100 px-1 text-gray-400 dark:bg-white/10 dark:text-gray-500"
                                x-text="job.queue || 'default'"></span>
                        </div>
                    </template>
                </div>
            </template>

            {{-- Idle state --}}
            <template x-if="!hasActivity && !snapshot.degraded">
                <div class="px-4 py-6 text-center">
                    <x-heroicon-o-check-circle class="mx-auto mb-1 h-6 w-6 text-success-500" />
                    <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Queue is idle') }}</p>
                </div>
            </template>

            {{-- Degraded state --}}
            <template x-if="snapshot.degraded">
                <div class="px-4 py-4 text-center text-xs text-gray-400 dark:text-gray-500">
                    {{ __('Queue data unavailable') }}
                </div>
            </template>
        </div>

        {{-- Footer link to Job Monitor --}}
        <div class="border-t border-gray-100 px-4 py-2 dark:border-white/10">
            <a href="{{ \App\Filament\Resources\QueueMonitor\QueueMonitorResource::getUrl('index') }}"
                class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                {{ __('View job history →') }}
            </a>
        </div>
    </div>
</div>
