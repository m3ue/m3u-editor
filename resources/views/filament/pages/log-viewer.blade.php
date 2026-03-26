<x-filament-panels::page>
    @php
        $logFiles = $this->getLogFiles();
        $result = $this->getParsedEntries();
        $entries = $result['entries'];
        $total = $result['total'];
        $pages = $result['pages'];

        $levels = ['all', 'DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

        $levelColor = [
            'DEBUG' => 'text-gray-500 dark:text-gray-400',
            'INFO' => 'text-blue-600 dark:text-blue-400',
            'NOTICE' => 'text-cyan-600 dark:text-cyan-400',
            'WARNING' => 'text-amber-500 dark:text-amber-400',
            'ERROR' => 'text-red-600 dark:text-red-400',
            'CRITICAL' => 'text-red-700 dark:text-red-400 font-semibold',
            'ALERT' => 'text-orange-600 dark:text-orange-400 font-semibold',
            'EMERGENCY' => 'text-pink-700 dark:text-pink-400 font-bold',
        ];

        $levelBg = [
            'DEBUG' => 'bg-gray-100 dark:bg-gray-800',
            'INFO' => 'bg-blue-50 dark:bg-blue-950/40',
            'NOTICE' => 'bg-cyan-50 dark:bg-cyan-950/40',
            'WARNING' => 'bg-amber-50 dark:bg-amber-950/40',
            'ERROR' => 'bg-red-50 dark:bg-red-950/40',
            'CRITICAL' => 'bg-red-100 dark:bg-red-950/60',
            'ALERT' => 'bg-orange-50 dark:bg-orange-950/40',
            'EMERGENCY' => 'bg-pink-100 dark:bg-pink-950/60',
        ];

        $levelBadge = [
            'DEBUG' => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
            'INFO' => 'bg-blue-100 text-blue-700 dark:bg-blue-900 dark:text-blue-300',
            'NOTICE' => 'bg-cyan-100 text-cyan-700 dark:bg-cyan-900 dark:text-cyan-300',
            'WARNING' => 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300',
            'ERROR' => 'bg-red-100 text-red-700 dark:bg-red-900 dark:text-red-300',
            'CRITICAL' => 'bg-red-200 text-red-800 dark:bg-red-900 dark:text-red-200',
            'ALERT' => 'bg-orange-100 text-orange-700 dark:bg-orange-900 dark:text-orange-300',
            'EMERGENCY' => 'bg-pink-200 text-pink-800 dark:bg-pink-900 dark:text-pink-200',
        ];
    @endphp

    {{-- ── Controls bar ─────────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-end gap-3 mb-4">

        {{-- File selector --}}
        <div class="flex flex-col gap-1 min-w-48">
            <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">Log file</span>
            </label>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="selectedFile">
                    @forelse($logFiles as $name => $path)
                        <option value="{{ $name }}">{{ $name }}</option>
                    @empty
                        <option value="">No log files found</option>
                    @endforelse
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        {{-- Level filter --}}
        <div class="flex flex-col gap-1">
            <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">Level</span>
            </label>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="levelFilter">
                    @foreach($levels as $lvl)
                        <option value="{{ $lvl === 'all' ? 'all' : $lvl }}">
                            {{ $lvl === 'all' ? 'All levels' : $lvl }}
                        </option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        {{-- Search --}}
        <div class="flex flex-col gap-1 flex-1 min-w-48">
            <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">Search</span>
            </label>
            <x-filament::input.wrapper prefix-icon="heroicon-o-magnifying-glass">
                <x-filament::input type="text" wire:model.live.debounce.300ms="search"
                    placeholder="Filter log entries…" />
            </x-filament::input.wrapper>
        </div>

        {{-- Per-page --}}
        <div class="flex flex-col gap-1">
            <label class="fi-fo-field-wrp-label inline-flex items-center gap-x-3">
                <span class="text-sm font-medium leading-6 text-gray-950 dark:text-white">Per page</span>
            </label>
            <x-filament::input.wrapper>
                <x-filament::input.select wire:model.live="perPage">
                    @foreach([25, 50, 100, 250] as $n)
                        <option value="{{ $n }}">{{ $n }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        </div>

        {{-- Stats --}}
        <div class="ml-auto text-sm text-gray-500 dark:text-gray-400 self-end pb-2 whitespace-nowrap">
            {{ number_format($total) }} {{ Str::plural('entry', $total) }}
        </div>

    </div>

    {{-- ── Log entries ──────────────────────────────────────────────────── --}}
    <div
        class="overflow-hidden rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-sm">

        @if(empty($logFiles))
            <div class="py-16 text-center text-gray-400 dark:text-gray-600">
                <x-heroicon-o-document-text class="w-12 h-12 mx-auto mb-3 opacity-40" />
                <p class="text-sm">No log files found in the configured log directory.</p>
            </div>

        @elseif(empty($entries))
            <div class="py-16 text-center text-gray-400 dark:text-gray-600">
                <x-heroicon-o-funnel class="w-12 h-12 mx-auto mb-3 opacity-40" />
                <p class="text-sm">No entries match your filters.</p>
            </div>

        @else
            <div class="divide-y divide-gray-100 dark:divide-gray-800">
                @foreach($entries as $i => $entry)
                    @php
                        $level = $entry['level'];
                        $bg = $levelBg[$level] ?? 'bg-gray-50 dark:bg-gray-900';
                        $badge = $levelBadge[$level] ?? 'bg-gray-200 text-gray-700';
                        $tc = $levelColor[$level] ?? 'text-gray-600';
                        $hasDetail = !empty($entry['context']) || !empty($entry['stack']);
                    @endphp

                    <div wire:key="entry-{{ $i }}" x-data="{ open: false }" class="{{ $bg }}">
                        {{-- Summary row --}}
                        <div class="flex items-start gap-3 px-4 py-3 {{ $hasDetail ? 'cursor-pointer hover:brightness-95 dark:hover:brightness-110' : '' }}"
                            @if($hasDetail) x-on:click="open = !open" @endif>
                            {{-- Expand chevron --}}
                            <div class="flex-shrink-0 mt-0.5 w-4">
                                @if($hasDetail)
                                    <x-heroicon-m-chevron-right class="w-4 h-4 text-gray-400 transition-transform duration-150"
                                        x-bind:class="{ 'rotate-90': open }" />
                                @endif
                            </div>

                            {{-- Level badge --}}
                            <span
                                class="flex-shrink-0 mt-0.5 inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold tracking-wide uppercase {{ $badge }}"
                                style="min-width:6rem;justify-content:center">
                                {{ $level }}
                            </span>

                            {{-- Date --}}
                            <span
                                class="flex-shrink-0 font-mono text-xs text-gray-400 dark:text-gray-500 mt-0.5 whitespace-nowrap">
                                {{ $entry['date'] }}
                            </span>

                            {{-- Env badge --}}
                            <span
                                class="flex-shrink-0 mt-0.5 text-[10px] px-1.5 py-0.5 rounded bg-gray-100 dark:bg-gray-800 text-gray-500 dark:text-gray-400 font-mono">
                                {{ $entry['env'] }}
                            </span>

                            {{-- Message --}}
                            <span class="flex-1 text-sm {{ $tc }} break-all">
                                {{ $entry['message'] }}
                            </span>
                        </div>

                        {{-- Detail panel (context + stack trace) --}}
                        @if($hasDetail)
                            <div x-show="open" x-collapse class="border-t border-gray-200 dark:border-gray-700">
                                <div class="px-4 py-3 space-y-3">

                                    @if(!empty($entry['context']))
                                        <div>
                                            <p
                                                class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-1">
                                                Context</p>
                                            <pre
                                                class="text-xs font-mono bg-gray-50 dark:bg-gray-950 border border-gray-200 dark:border-gray-700 rounded p-3 overflow-x-auto whitespace-pre-wrap break-all text-gray-700 dark:text-gray-300">{{ $entry['context'] }}</pre>
                                        </div>
                                    @endif

                                    @if(!empty($entry['stack']))
                                        <div>
                                            <p
                                                class="text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500 mb-1">
                                                Stack trace</p>
                                            <pre
                                                class="text-xs font-mono bg-gray-950 border border-gray-800 rounded p-3 overflow-x-auto whitespace-pre-wrap break-all text-green-300">{{ $entry['stack'] }}</pre>
                                        </div>
                                    @endif

                                </div>
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    {{-- ── Pagination ───────────────────────────────────────────────────── --}}
    @if($pages > 1)
        <div class="mt-4 flex items-center justify-between text-sm text-gray-500 dark:text-gray-400">
            <span>Page {{ $page }} of {{ $pages }}</span>

            <div class="flex gap-2">
                <x-filament::button size="sm" color="gray" wire:click="prevPage" :disabled="$page <= 1"
                    icon="heroicon-o-chevron-left">
                    Previous
                </x-filament::button>

                <x-filament::button size="sm" color="gray" wire:click="nextPage({{ $pages }})" :disabled="$page >= $pages"
                    icon="heroicon-o-chevron-right" icon-position="after">
                    Next
                </x-filament::button>
            </div>
        </div>
    @endif

</x-filament-panels::page>