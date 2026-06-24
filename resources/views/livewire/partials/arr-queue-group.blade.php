<x-filament::section :heading="$queueGroup['integration']['name']" collapsible="true" compact :collapse-id="'queue-group-' . $queueGroup['integration']['id']" persist-collapsed>
    <x-slot name="afterHeader">
        @if ($queueGroup['error'])
            <x-filament::badge color="danger" icon="heroicon-o-exclamation-circle">
                {{ __('Unreachable') }}
            </x-filament::badge>
        @else
            <x-filament::badge color="gray">
                {{ trans_choice(':count item|:count items', count($queueGroup['items'])) }}
            </x-filament::badge>
        @endif
    </x-slot>

    @if (!$queueGroup['error'] && count($queueGroup['items']) > 0)
        <div class="divide-y divide-gray-100 dark:divide-gray-700/50">
            @foreach ($queueGroup['items'] as $item)
                @php
                    $badge = \App\Livewire\ArrQueueMonitor::statusBadge($item['status'] ?? 'unknown');
                    $showProgress = in_array($item['status'] ?? '', ['downloading', 'queued', 'paused', 'importing']);
                @endphp
                <div class="py-3 first:pt-0 last:pb-0">
                    <div class="flex items-start justify-between gap-3">
                        <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate flex-1 min-w-0">
                            {{ $item['title'] ?? __('Unknown') }}
                        </p>
                        <div class="flex items-center gap-1 shrink-0">
                            <x-filament::badge :color="$badge['color']">
                                {{ $badge['label'] }}
                            </x-filament::badge>
                            @if (($item['source'] ?? null) === 'media_request')
                                <x-filament::icon-button color="success" icon="heroicon-o-check" size="sm"
                                    :tooltip="__('Approve')"
                                    wire:click="approveRequest({{ $item['media_request_id'] }})" />
                                <x-filament::icon-button color="danger" icon="heroicon-o-x-mark" size="sm"
                                    :tooltip="__('Reject')"
                                    wire:click="rejectRequest({{ $item['media_request_id'] }})" />
                            @elseif ($item['can_dismiss'] ?? false)
                                <x-filament::icon-button color="gray" icon="heroicon-o-x-mark" size="sm"
                                    :tooltip="__('Dismiss')"
                                    wire:click="dismissItem('{{ $item['dismiss_source'] }}', '{{ $item['dismiss_key'] }}')" />
                            @endif
                        </div>
                    </div>

                    @if ($item['episode'] ?? null)
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400 truncate">{{ $item['episode'] }}</p>
                    @endif

                    <div class="mt-1 flex items-center gap-3 text-xs text-gray-500 dark:text-gray-500">
                        @if ($item['quality'] ?? null)
                            <span class="font-medium text-gray-600 dark:text-gray-400">{{ $item['quality'] }}</span>
                        @endif
                        @if ($item['protocol'] ?? null)
                            <span
                                class="uppercase tracking-wide text-gray-400 dark:text-gray-600">{{ $item['protocol'] === 'usenet' ? 'NZB' : ucfirst($item['protocol']) }}</span>
                        @endif
                        @if ($showProgress && ($item['size'] ?? 0) > 0)
                            <span>{{ $item['formattedSize'] }}</span>
                        @endif
                        @if ($showProgress && ($item['timeLeft'] ?? null))
                            <span>·</span>
                            <span>{{ $item['timeLeft'] }} {{ __('left') }}</span>
                        @endif
                        @if ($showProgress)
                            <span
                                class="ml-auto font-medium text-gray-700 dark:text-gray-300">{{ $item['progress'] }}%</span>
                        @elseif($item['last_event_at'] ?? null)
                            <span class="ml-auto text-xs text-gray-400 dark:text-gray-600">
                                {{ \Carbon\Carbon::parse($item['last_event_at'])->diffForHumans() }}
                            </span>
                        @endif
                    </div>

                    @if (($item['source'] ?? null) === 'media_request' && ($item['requested_by'] ?? null))
                        <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-600 truncate">
                            {{ __('Requested by: :name', ['name' => $item['requested_by']]) }}
                        </p>
                    @elseif ($item['indexer'] ?? null)
                        <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-600 truncate">{{ $item['indexer'] }}</p>
                    @endif

                    @if ($showProgress)
                        <div class="mt-1.5 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5 overflow-hidden">
                            <div @class([
                                'h-1.5 rounded-full transition-all duration-500',
                                'bg-primary-500' => $badge['color'] === 'primary',
                                'bg-warning-500' => $badge['color'] === 'warning',
                                'bg-success-500' => $badge['color'] === 'success',
                                'bg-danger-500' => $badge['color'] === 'danger',
                                'bg-gray-400' => $badge['color'] === 'gray',
                            ]) style="width: {{ $item['progress'] }}%"></div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @elseif(!$queueGroup['error'])
        <p class="text-xs text-center text-gray-400 dark:text-gray-600">{{ __('Queue is empty') }}</p>
    @endif
</x-filament::section>
