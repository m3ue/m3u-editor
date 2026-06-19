<div class="rounded-lg border border-gray-200 dark:border-gray-700 overflow-hidden">
    {{-- Integration header --}}
    <div class="flex items-center justify-between px-4 py-2.5 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
        <span class="text-xs font-medium text-gray-600 dark:text-gray-400 uppercase tracking-wide">
            {{ $queueGroup['integration']['name'] }}
        </span>
        @if($queueGroup['error'])
            <span class="inline-flex items-center gap-1 text-xs text-danger-600 dark:text-danger-400">
                <x-heroicon-o-exclamation-circle class="w-3.5 h-3.5" />
                {{ __('Unreachable') }}
            </span>
        @else
            <span class="text-xs text-gray-500 dark:text-gray-500">
                {{ trans_choice(':count item|:count items', count($queueGroup['items'])) }}
            </span>
        @endif
    </div>

    @if(! $queueGroup['error'] && count($queueGroup['items']) > 0)
        <div class="divide-y divide-gray-100 dark:divide-gray-700/50">
            @foreach($queueGroup['items'] as $item)
                @php
                    $badge = \App\Livewire\ArrQueueMonitor::statusBadge($item['status'] ?? 'unknown');
                    $showProgress = in_array($item['status'] ?? '', ['downloading', 'queued', 'paused', 'importing']);
                @endphp
                <div class="px-4 py-3 bg-white dark:bg-gray-900/30">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate flex-1 min-w-0">
                            {{ $item['title'] ?? __('Unknown') }}
                        </p>
                        <div class="flex items-center gap-1.5 flex-shrink-0">
                        <span @class([
                            'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap flex-shrink-0',
                            'bg-primary-100 text-primary-700 dark:bg-primary-900/30 dark:text-primary-400' => $badge['color'] === 'primary',
                            'bg-warning-100 text-warning-700 dark:bg-warning-900/30 dark:text-warning-400' => $badge['color'] === 'warning',
                            'bg-success-100 text-success-700 dark:bg-success-900/30 dark:text-success-400' => $badge['color'] === 'success',
                            'bg-danger-100 text-danger-700 dark:bg-danger-900/30 dark:text-danger-400' => $badge['color'] === 'danger',
                            'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400' => $badge['color'] === 'gray',
                        ])>
                            {{ $badge['label'] }}
                        </span>
                        @if($item['can_dismiss'] ?? false)
                            <button
                                wire:click="dismissItem('{{ $item['dismiss_source'] }}', '{{ $item['dismiss_key'] }}')"
                                wire:loading.attr="disabled"
                                title="{{ __('Dismiss') }}"
                                class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-300 transition-colors"
                            >
                                <x-heroicon-o-x-mark class="w-3.5 h-3.5" />
                            </button>
                        @endif
                        </div>
                    </div>

                    @if($item['episode'] ?? null)
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400 truncate">{{ $item['episode'] }}</p>
                    @endif

                    <div class="mt-1 flex items-center gap-3 text-xs text-gray-500 dark:text-gray-500">
                        @if($item['quality'] ?? null)
                            <span class="font-medium text-gray-600 dark:text-gray-400">{{ $item['quality'] }}</span>
                        @endif
                        @if($item['protocol'] ?? null)
                            <span class="uppercase tracking-wide text-gray-400 dark:text-gray-600">{{ $item['protocol'] === 'usenet' ? 'NZB' : ucfirst($item['protocol']) }}</span>
                        @endif
                        @if($showProgress && ($item['size'] ?? 0) > 0)
                            <span>{{ $item['formattedSize'] }}</span>
                        @endif
                        @if($showProgress && ($item['timeLeft'] ?? null))
                            <span>·</span>
                            <span>{{ $item['timeLeft'] }} {{ __('left') }}</span>
                        @endif
                        @if($showProgress)
                            <span class="ml-auto font-medium text-gray-700 dark:text-gray-300">{{ $item['progress'] }}%</span>
                        @elseif($item['last_event_at'] ?? null)
                            <span class="ml-auto text-gray-400 dark:text-gray-600 text-xs">
                                {{ \Carbon\Carbon::parse($item['last_event_at'])->diffForHumans() }}
                            </span>
                        @endif
                    </div>

                    @if($item['indexer'] ?? null)
                        <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-600 truncate">{{ $item['indexer'] }}</p>
                    @endif

                    @if($showProgress)
                        <div class="mt-1.5 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5 overflow-hidden">
                            <div
                                @class([
                                    'h-1.5 rounded-full transition-all duration-500',
                                    'bg-primary-500' => $badge['color'] === 'primary',
                                    'bg-warning-500' => $badge['color'] === 'warning',
                                    'bg-success-500' => $badge['color'] === 'success',
                                    'bg-danger-500' => $badge['color'] === 'danger',
                                    'bg-gray-400' => $badge['color'] === 'gray',
                                ])
                                style="width: {{ $item['progress'] }}%"
                            ></div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @elseif(! $queueGroup['error'])
        <div class="px-4 py-6 text-center bg-white dark:bg-gray-900/30">
            <p class="text-xs text-gray-400 dark:text-gray-600">{{ __('Queue is empty') }}</p>
        </div>
    @endif
</div>
