@php
    $endpoint = route('admin.queue-indicator');

    $horizonUrl = url('/'.trim((string) config('horizon.path', 'horizon'), '/'));

    foreach (['horizon.index', 'horizon.dashboard', 'horizon'] as $routeName) {
        if (! \Illuminate\Support\Facades\Route::has($routeName)) {
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
        snapshot: { running: 0, queued: 0, upcoming: [], degraded: false, as_of: null },
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
            } catch (error) {
                this.snapshot = { running: 0, queued: 0, upcoming: [], degraded: true, reason: 'request_failed', as_of: new Date().toISOString() };
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
        class="relative flex h-9 w-9 items-center justify-center rounded-full text-gray-500 transition hover:bg-gray-50 hover:text-primary-600 focus:outline-none focus:ring-2 focus:ring-primary-600 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-primary-400"
        x-on:click="open = ! open"
        aria-label="Queue status"
    >
        <x-heroicon-m-circle-stack class="h-5 w-5" />
        <span
            class="absolute -right-1 -top-1 min-w-5 rounded-full px-1.5 py-0.5 text-center text-[0.65rem] font-semibold leading-none text-white"
            x-bind:class="snapshot.degraded ? 'bg-warning-500' : ((snapshot.running + snapshot.queued) > 0 ? 'bg-primary-600' : 'bg-gray-400')"
            x-text="loading ? '...' : (snapshot.running + snapshot.queued)"
        ></span>
    </button>

    <div
        x-cloak
        x-show="open"
        x-transition.origin.top.right
        x-on:click.outside="open = false"
        class="absolute right-0 top-11 z-50 w-80 rounded-xl bg-white p-4 shadow-lg ring-1 ring-gray-950/10 dark:bg-gray-900 dark:ring-white/10"
    >
        <div class="mb-3 flex items-start justify-between gap-3">
            <div>
                <p class="text-sm font-semibold text-gray-950 dark:text-white">Queue Status</p>
                <p class="text-xs text-gray-500 dark:text-gray-400">Aktualisiert alle 10 Sekunden.</p>
            </div>
            <a href="{{ $horizonUrl }}" target="_blank" class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                Queue Manager
            </a>
        </div>

        <div class="grid grid-cols-2 gap-2 text-sm">
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                <p class="text-xs text-gray-500 dark:text-gray-400">Laufend</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white" x-text="snapshot.running"></p>
            </div>
            <div class="rounded-lg bg-gray-50 p-3 dark:bg-white/5">
                <p class="text-xs text-gray-500 dark:text-gray-400">Geplant</p>
                <p class="text-lg font-semibold text-gray-950 dark:text-white" x-text="snapshot.queued"></p>
            </div>
        </div>

        <template x-if="snapshot.degraded">
            <p class="mt-3 rounded-lg bg-warning-50 p-2 text-xs text-warning-700 dark:bg-warning-500/10 dark:text-warning-300">
                Queue-Daten sind gerade nicht verfügbar. Die Seite bleibt weiter nutzbar.
            </p>
        </template>

        <div class="mt-3">
            <p class="mb-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Nächste Jobs</p>
            <template x-if="snapshot.upcoming.length === 0">
                <p class="rounded-lg bg-gray-50 p-3 text-sm text-gray-500 dark:bg-white/5 dark:text-gray-400">Keine wartenden Jobs.</p>
            </template>
            <div class="space-y-2">
                <template x-for="job in snapshot.upcoming" x-bind:key="job.id || job.name">
                    <div class="rounded-lg border border-gray-200 p-2 text-xs dark:border-white/10">
                        <p class="truncate font-medium text-gray-950 dark:text-white" x-text="job.name || 'Unbekannter Job'"></p>
                        <p class="text-gray-500 dark:text-gray-400">
                            <span x-text="job.connection || 'connection'"></span>
                            <span>/</span>
                            <span x-text="job.queue || 'queue'"></span>
                        </p>
                    </div>
                </template>
            </div>
        </div>
    </div>
</div>
