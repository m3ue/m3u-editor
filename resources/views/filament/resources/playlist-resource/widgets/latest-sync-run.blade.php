@php
    /** @var \App\Filament\Resources\Playlists\Widgets\LatestSyncRun $this */
    $run = $this->getLatestRun();
    $rows = $this->getTimeline();
    $url = $this->getViewRunUrl();
    $isRunning = $run && ! $run->isFinished();

    $statusColors = [
        'pending' => 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300',
        'running' => 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',
        'completed' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300',
        'failed' => 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',
        'cancelled' => 'bg-amber-100 text-amber-700 dark:bg-amber-900/40 dark:text-amber-300',
    ];

    $finishedCount = collect($rows)->filter(fn ($r) => in_array($r['status']->value, ['completed', 'failed', 'skipped'], true))->count();
    $totalCount = count($rows);
@endphp

<x-filament-widgets::widget>
    <x-filament::section>
        <div @if($isRunning) wire:poll.2s @endif>
            @if(! $run)
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('No sync runs yet for this playlist.') }}
                </p>
            @else
                <div class="flex flex-wrap items-center justify-between gap-3 mb-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ __('Latest sync run') }}
                        </span>
                        <span class="text-xs px-1.5 py-0.5 rounded {{ $statusColors[$run->status->value] ?? $statusColors['pending'] }}">
                            {{ $run->status->value }}
                        </span>
                        <span class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300">
                            {{ $run->kind }}
                        </span>
                        <span class="text-xs text-gray-500 dark:text-gray-400">
                            {{ $finishedCount }} / {{ $totalCount }} {{ __('phases') }}
                        </span>
                        @if($run->started_at)
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                · {{ __('started') }} {{ $run->started_at->diffForHumans() }}
                            </span>
                        @endif
                    </div>
                    @if($url)
                        <a href="{{ $url }}" class="text-xs font-medium text-primary-600 hover:underline dark:text-primary-400">
                            {{ __('View full run') }} &rarr;
                        </a>
                    @endif
                </div>

                @if(empty($rows))
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        {{ __('No phases recorded yet.') }}
                    </p>
                @else
                    <div class="flex flex-wrap gap-1.5">
                        @foreach($rows as $row)
                            @php
                                $dotColor = match ($row['status']->value) {
                                    'completed' => 'bg-emerald-500',
                                    'running' => 'bg-blue-500 animate-pulse',
                                    'failed' => 'bg-red-500',
                                    'skipped' => 'bg-gray-400',
                                    default => 'bg-gray-300 dark:bg-gray-600',
                                };
                            @endphp
                            <span
                                class="inline-flex items-center gap-1.5 rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-2 py-1 text-xs"
                                title="{{ $row['label'] }} ({{ $row['status']->value }})"
                            >
                                <span class="inline-block h-2 w-2 rounded-full {{ $dotColor }}"></span>
                                <span class="text-gray-700 dark:text-gray-200">{{ $row['label'] }}</span>
                            </span>
                        @endforeach
                    </div>
                @endif
            @endif
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
