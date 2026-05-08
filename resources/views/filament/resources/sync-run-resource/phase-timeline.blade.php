@php
    /** @var \App\Models\SyncRun $record */
    $rows = \App\Filament\Resources\SyncRuns\SyncRunResource::buildPhaseTimeline($record);
    $isRunning = ! $record->isFinished();
@endphp

<div @if($isRunning) wire:poll.2s @endif>
    @if(empty($rows))
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('No phases recorded for this run.') }}
        </p>
    @else
        <ol class="space-y-2">
            @php $previousGroup = null; @endphp
            @foreach($rows as $row)
                @php
                    $group = $row['parallel_group'] ?? $row['chain_group'];
                    $groupBadge = match (true) {
                        $row['parallel_group'] !== null => ['label' => __('parallel'), 'color' => 'bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300'],
                        $row['chain_group'] !== null => ['label' => __('chain'), 'color' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300'],
                        default => null,
                    };
                    $statusColor = match ($row['status']->value) {
                        'completed' => 'bg-emerald-500',
                        'running' => 'bg-blue-500 animate-pulse',
                        'failed' => 'bg-red-500',
                        'skipped' => 'bg-gray-400',
                        default => 'bg-gray-300 dark:bg-gray-600',
                    };
                    $statusBadge = match ($row['status']->value) {
                        'completed' => ['label' => __('completed'), 'color' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'],
                        'running' => ['label' => __('running'), 'color' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300'],
                        'failed' => ['label' => __('failed'), 'color' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300'],
                        'skipped' => ['label' => __('skipped'), 'color' => 'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-300'],
                        default => ['label' => __('pending'), 'color' => 'bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400'],
                    };
                    $groupChanged = $group !== $previousGroup;
                    $previousGroup = $group;
                @endphp

                @if($groupChanged && $group !== null)
                    <li class="flex items-center gap-2 pt-1 text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">
                        @if($groupBadge)
                            <span class="px-2 py-0.5 rounded {{ $groupBadge['color'] }}">{{ $groupBadge['label'] }}</span>
                        @endif
                        <span>{{ __('group') }} {{ $group }}</span>
                    </li>
                @endif

                <li class="flex items-start gap-3 rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-3 py-2 {{ $group !== null ? 'ml-4' : '' }}">
                    <span class="mt-1.5 inline-block h-2.5 w-2.5 shrink-0 rounded-full {{ $statusColor }}"></span>
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium text-sm text-gray-900 dark:text-gray-100">
                                {{ $row['label'] }}
                            </span>
                            <span class="text-xs px-1.5 py-0.5 rounded {{ $statusBadge['color'] }}">
                                {{ $statusBadge['label'] }}
                            </span>
                            @if(! $row['required'])
                                <span class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                                    {{ __('optional') }}
                                </span>
                            @endif
                        </div>
                        <div class="text-xs text-gray-500 dark:text-gray-400 font-mono">
                            {{ $row['slug'] }}
                            @if($row['started_at'])
                                · {{ __('started') }} {{ $row['started_at']->diffForHumans() }}
                            @endif
                        </div>
                        @if($row['error'])
                            <div class="mt-1 text-xs text-red-600 dark:text-red-400">
                                {{ $row['error'] }}
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>
