{{--
    Inline "last sync" display for a DynamicGroup: relative timestamp with
    +added / -removed chips next to it. Click to expand a list of the actual
    titles. Uses HTML <details>/<summary> (project precedent: see
    m3u-proxy-stream-monitor.blade.php:757) - keeps it accessible and avoids
    a custom Alpine accordion.

    Falls back to a plain relative time string when no captured run exists
    (e.g. the feature flag was enabled after the group's last sync).
--}}
@php
    /** @var \App\Models\DynamicGroup $record */
    $record = $getRecord();

    $latestRunId = \App\Models\DynamicGroupItemSnapshot::query()
        ->where('dynamic_group_id', $record->id)
        ->whereNotNull('sync_run_id')
        ->max('sync_run_id');

    $lastRun = $latestRunId !== null
        ? \App\Models\SyncRun::query()->visibleTo(auth()->user())->find($latestRunId)
        : null;

    if ($latestRunId !== null && $lastRun === null) {
        $latestRunId = null;
    }

    if ($latestRunId !== null) {
        $diff = \App\Models\DynamicGroupItemSnapshot::diffForRun($record->id, $latestRunId);
        $itemTypes = \App\Models\DynamicGroupItemSnapshot::itemsForRun($record->id, $latestRunId);

        $resolveTitle = function (int $itemId) use ($itemTypes): ?string {
            $type = $itemTypes[$itemId] ?? null;
            if ($type === \App\Models\Channel::class) {
                return \App\Models\Channel::where('id', $itemId)->value('title');
            }
            if ($type === \App\Models\Series::class) {
                return \App\Models\Series::where('id', $itemId)->value('name');
            }

            return null;
        };

        $addedCount = $diff['added']->count();
        $removedCount = $diff['removed']->count();
        $hasDiff = $diff['has_previous'] && ($addedCount > 0 || $removedCount > 0);
    }
@endphp

@if ($latestRunId === null)
    <span class="text-sm text-gray-500 dark:text-gray-400">
        {{ $record->last_synced_at?->diffForHumans() ?? __('Never') }}
    </span>
@else
    <details class="text-sm">
        <summary class="inline-flex cursor-pointer list-none items-center gap-2 text-gray-700 hover:text-gray-900 dark:text-gray-300 dark:hover:text-white">
            <span>{{ $lastRun->started_at?->diffForHumans() ?? $record->last_synced_at?->diffForHumans() ?? __('Never') }}</span>

            @if ($diff['has_previous'])
                @if ($addedCount > 0)
                    <x-filament::badge color="success" size="sm">+{{ $addedCount }}</x-filament::badge>
                @endif
                @if ($removedCount > 0)
                    <x-filament::badge color="danger" size="sm">-{{ $removedCount }}</x-filament::badge>
                @endif
            @else
                <x-filament::badge color="gray" size="sm">{{ __('baseline') }}</x-filament::badge>
            @endif
        </summary>

        @if ($hasDiff)
            <div class="mt-3 space-y-3 border-l-2 border-gray-200 pl-4 dark:border-gray-700">
                @if ($addedCount > 0)
                    <div>
                        <p class="text-success-700 dark:text-success-400 text-xs font-semibold">
                            {{ __('+ Added (:count)', ['count' => $addedCount]) }}
                        </p>
                        <ul class="mt-1 space-y-0.5 text-xs text-gray-700 dark:text-gray-300">
                            @foreach ($diff['added']->take(50) as $itemId)
                                <li>{{ $resolveTitle((int) $itemId) ?: '#'.$itemId }}</li>
                            @endforeach
                            @if ($diff['added']->count() > 50)
                                <li class="text-gray-500 italic dark:text-gray-400">
                                    {{ __('and :count more…', ['count' => $diff['added']->count() - 50]) }}
                                </li>
                            @endif
                        </ul>
                    </div>
                @endif

                @if ($removedCount > 0)
                    <div>
                        <p class="text-danger-700 dark:text-danger-400 text-xs font-semibold">
                            {{ __('- Removed (:count)', ['count' => $removedCount]) }}
                        </p>
                        <ul class="mt-1 space-y-0.5 text-xs text-gray-700 dark:text-gray-300">
                            @foreach ($diff['removed']->take(50) as $itemId)
                                <li>{{ $resolveTitle((int) $itemId) ?: '#'.$itemId }}</li>
                            @endforeach
                            @if ($diff['removed']->count() > 50)
                                <li class="text-gray-500 italic dark:text-gray-400">
                                    {{ __('and :count more…', ['count' => $diff['removed']->count() - 50]) }}
                                </li>
                            @endif
                        </ul>
                    </div>
                @endif
            </div>
        @endif
    </details>
@endif
