<x-filament-widgets::widget>
    <div wire:poll.5s.visible>
        @php($data = $this->getProgressData())
        @if($data['processing'] && $data['progress'] < 100)
            <div class="w-full h-5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden relative mb-2">
                <div class="absolute left-0 top-0 h-5 bg-primary-600 transition-all duration-700 ease-in-out"
                    style="width: {{ $data['progress'] }}%"></div>
                <div class="absolute left-0 top-0 w-full h-5 flex items-center justify-center">
                    <span class="text-xs font-medium text-primary-900 dark:text-primary-100 select-none">Channel Sync
                        Progress: <strong>{{ $data['progress'] }}%</strong></span>
                </div>
            </div>
        @endif
        @if($data['processing'] && $data['seriesProgress'] < 100)
            <div class="w-full h-5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden relative">
                <div class="absolute left-0 top-0 h-5 bg-primary-600 transition-all duration-700 ease-in-out"
                    style="width: {{ $data['seriesProgress'] }}%"></div>
                <div class="absolute left-0 top-0 w-full h-5 flex items-center justify-center">
                    <span class="text-xs font-medium text-primary-900 dark:text-primary-100 select-none">Series Sync
                        Progress: <strong>{{ $data['seriesProgress'] }}%</strong></span>
                </div>
            </div>
        @endif
        @if($data['processing'] && $data['vodProgress'] < 100)
            <div class="w-full h-5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden relative">
                <div class="absolute left-0 top-0 h-5 bg-primary-600 transition-all duration-700 ease-in-out"
                    style="width: {{ $data['vodProgress'] }}%"></div>
                <div class="absolute left-0 top-0 w-full h-5 flex items-center justify-center">
                    <span class="text-xs font-medium text-primary-900 dark:text-primary-100 select-none">VOD Sync Progress:
                        <strong>{{ $data['vodProgress'] }}%</strong></span>
                </div>
            </div>
        @endif
    </div>
</x-filament-widgets::widget>