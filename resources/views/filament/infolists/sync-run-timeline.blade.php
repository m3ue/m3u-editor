@php
    /** @var \App\Models\SyncRun $record */
    $record = $getRecord();
    $phases = $getState();

    // While a run is active and only the upfront `Import` phase exists, the real
    // post-import phases haven't been resolved yet (ProcessM3uImportComplete will
    // expand the plan once channel/series rows are populated). Show a synthetic
    // placeholder row so users see "something comes after Import" without us
    // committing to specific phases that may or may not actually run.
    $showPostProcessingPlaceholder = $record->status === \App\Enums\SyncRunStatus::Running->value
        && count($phases) === 1
        && ($phases[0]['phase'] ?? null) === \App\Enums\SyncRunPhase::Import->value
        && $record->current_phase === \App\Enums\SyncRunPhase::Import->value;
@endphp

@if (empty($phases))
    <p class="text-sm text-gray-500 dark:text-gray-400">No phases recorded.</p>
@else
    <ul class="space-y-0">
        @foreach ($phases as $phase)
            @php
                $status   = $phase['status'];
                $isLast   = $loop->last && ! $showPostProcessingPlaceholder;
            @endphp

            {{--
                Each item: relative wrapper with pb-6 (except last).
                The absolute span uses h-full which equals 100 % of this wrapper's
                padding box — so it always reaches exactly to the next circle's center,
                regardless of how tall the content on the right is.
                ring-4 on the circle masks the line where it passes through.
            --}}
            <li class="relative {{ $isLast ? '' : 'pb-6' }}">

                {{-- Connector line: starts at top-8 (below the circle) so it never overlaps it --}}
                @if (! $isLast)
                    <span
                        class="absolute left-4 top-8 -ml-px h-full w-0.5
                            {{ $status === 'completed'
                                ? 'bg-success-200 dark:bg-success-800'
                                : 'bg-gray-200 dark:bg-gray-700' }}"
                        aria-hidden="true"
                    ></span>
                @endif

                <div class="relative flex gap-4">

                    {{-- Status circle --}}
                    @if ($status === 'completed')
                        <div class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-success-100 text-success-600 dark:bg-success-900 dark:text-success-400">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" />
                            </svg>
                        </div>
                    @elseif ($status === 'running')
                        <div class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-warning-100 text-warning-600 dark:bg-warning-900 dark:text-warning-400">
                            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24" aria-hidden="true">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" />
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" />
                            </svg>
                        </div>
                    @elseif ($status === 'failed')
                        <div class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-danger-100 text-danger-600 dark:bg-danger-900 dark:text-danger-400">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </div>
                    @elseif ($status === 'skipped')
                        <div class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-400 dark:bg-gray-800 dark:text-gray-500">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 12h14" />
                            </svg>
                        </div>
                    @else
                        {{-- pending --}}
                        <div class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                            <div class="h-2.5 w-2.5 rounded-full bg-gray-300 dark:bg-gray-600"></div>
                        </div>
                    @endif

                    {{-- Content --}}
                    <div class="flex min-w-0 flex-1 items-center justify-between gap-4 pt-0.5">
                        <div class="min-w-0">
                            <p class="text-sm font-medium leading-6
                                {{ in_array($status, ['pending', 'skipped'])
                                    ? 'text-gray-400 dark:text-gray-500'
                                    : 'text-gray-900 dark:text-gray-100' }}">
                                {{ $phase['label'] }}
                            </p>
                            @if (! empty($phase['duration']))
                                <p class="text-xs leading-5 text-gray-500 dark:text-gray-400">
                                    {{ $phase['duration'] }}
                                </p>
                            @elseif ($status === 'running')
                                <p class="text-xs leading-5 text-warning-600 dark:text-warning-400">
                                    Running…
                                </p>
                            @endif
                        </div>

                        {{-- Status badge --}}
                        <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                            @if ($status === 'completed') bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400
                            @elseif ($status === 'running') bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400
                            @elseif ($status === 'failed') bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400
                            @else bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400
                            @endif">
                            {{ ucfirst($status) }}
                        </span>
                    </div>

                </div>
            </li>
        @endforeach

        @if ($showPostProcessingPlaceholder)
            {{-- Synthetic placeholder shown only while the real post-import phases
                 are still being resolved. Replaced with concrete phase rows the
                 moment ProcessM3uImportComplete expands the pipeline. --}}
            <li class="relative">
                <div class="relative flex gap-4">
                    <div class="relative z-10 flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-gray-100 dark:bg-gray-800">
                        <div class="h-2.5 w-2.5 rounded-full bg-gray-300 dark:bg-gray-600"></div>
                    </div>
                    <div class="flex min-w-0 flex-1 items-center justify-between gap-4 pt-0.5">
                        <div class="min-w-0">
                            <p class="text-sm font-medium leading-6 text-gray-400 dark:text-gray-500">
                                Post-processing
                            </p>
                            <p class="text-xs leading-5 text-gray-500 dark:text-gray-400 italic">
                                Resolving phases…
                            </p>
                        </div>
                        <span class="inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-medium bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                            Pending
                        </span>
                    </div>
                </div>
            </li>
        @endif
    </ul>
@endif
