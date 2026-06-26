<div wire:poll.10s="loadQueue">
    @if(count($items) === 0)
        <div class="flex flex-col items-center justify-center py-8 text-center">
            <x-heroicon-o-inbox class="w-10 h-10 text-gray-300 dark:text-gray-600" />
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                {{ __('No requests yet. Use the search below to request content.') }}
            </p>
        </div>
    @else
        <div class="divide-y divide-gray-100 dark:divide-gray-700/50">
            @foreach($items as $item)
                @php
                    $badge = \App\Livewire\ArrQueueMonitor::statusBadge($item['status']);
                    $showProgress = in_array($item['status'], ['downloading', 'queued', 'paused', 'importing']);
                @endphp
                <div class="py-3 first:pt-0 last:pb-0">
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 flex-1 truncate text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ $item['title'] }}
                        </p>
                        <div class="flex shrink-0 items-center gap-1">
                            <x-filament::badge :color="$badge['color']">
                                {{ __($badge['label']) }}
                            </x-filament::badge>
                            @if($item['can_dismiss'] ?? false)
                                <x-filament::icon-button
                                    color="gray"
                                    icon="heroicon-o-x-mark"
                                    size="sm"
                                    :tooltip="__('Dismiss')"
                                    wire:click="dismissRequest({{ $item['id'] }})"
                                />
                            @endif
                        </div>
                    </div>

                    @if($item['episode'] ?? null)
                        <p class="mt-0.5 truncate text-xs text-gray-500 dark:text-gray-400">{{ $item['episode'] }}</p>
                    @endif

                    <div class="mt-1 flex items-center gap-3 text-xs text-gray-500 dark:text-gray-500">
                        @if($item['quality'] ?? null)
                            <span class="font-medium text-gray-600 dark:text-gray-400">{{ $item['quality'] }}</span>
                        @endif
                        @if($item['protocol'] ?? null)
                            <span class="uppercase tracking-wide text-gray-400 dark:text-gray-600">
                                {{ $item['protocol'] === 'usenet' ? 'NZB' : ucfirst($item['protocol']) }}
                            </span>
                        @endif
                        @if($showProgress && ($item['size'] ?? 0) > 0)
                            <span>{{ $item['formattedSize'] }}</span>
                        @endif
                        @if($showProgress && ($item['timeLeft'] ?? null))
                            <span>&middot;</span>
                            <span>{{ $item['timeLeft'] }} {{ __('left') }}</span>
                        @endif
                        @if($showProgress)
                            <span class="ml-auto font-medium text-gray-700 dark:text-gray-300">{{ $item['progress'] }}%</span>
                        @else
                            <span class="ml-auto text-xs text-gray-400 dark:text-gray-600">
                                {{ $item['integration_name'] }}
                                &middot;
                                {{ $item['requested_at'] ? \Carbon\Carbon::parse($item['requested_at'])->diffForHumans() : '' }}
                            </span>
                        @endif
                    </div>

                    @if($showProgress)
                        <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
                            <div @class([
                                'h-1.5 rounded-full transition-all duration-500',
                                'bg-primary-500' => $badge['color'] === 'primary',
                                'bg-warning-500' => $badge['color'] === 'warning',
                                'bg-success-500' => $badge['color'] === 'success',
                                'bg-danger-500' => $badge['color'] === 'danger',
                                'bg-gray-400'    => $badge['color'] === 'gray',
                            ]) style="width: {{ $item['progress'] }}%"></div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</div>
