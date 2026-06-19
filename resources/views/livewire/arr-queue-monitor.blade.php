<div wire:poll.10s="loadQueues">
    @php
        $hasSonarr = ! empty($this->sonarrQueues);
        $hasRadarr = ! empty($this->radarrQueues);
        $sideBy   = $hasSonarr && $hasRadarr;
    @endphp

    @if(! $hasSonarr && ! $hasRadarr)
        <x-filament::empty-state
            icon="heroicon-o-arrow-down-tray"
            heading="{{ __('No integrations configured') }}"
            description="{{ __('Enable a Sonarr or Radarr integration to monitor its download queue here.') }}"
        />
    @else
        <div @class(['grid gap-6', 'grid-cols-1 lg:grid-cols-2' => $sideBy, 'grid-cols-1' => ! $sideBy])>

            @if($hasSonarr)
                <div class="flex flex-col gap-4">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-tv class="w-5 h-5 text-primary-500" />
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('Sonarr — TV Shows') }}</h2>
                    </div>
                    @foreach($this->sonarrQueues as $queueGroup)
                        @include('livewire.partials.arr-queue-group', ['queueGroup' => $queueGroup])
                    @endforeach
                </div>
            @endif

            @if($hasRadarr)
                <div class="flex flex-col gap-4">
                    <div class="flex items-center gap-2">
                        <x-heroicon-o-film class="w-5 h-5 text-warning-500" />
                        <h2 class="text-base font-semibold text-gray-900 dark:text-gray-100">{{ __('Radarr — Movies') }}</h2>
                    </div>
                    @foreach($this->radarrQueues as $queueGroup)
                        @include('livewire.partials.arr-queue-group', ['queueGroup' => $queueGroup])
                    @endforeach
                </div>
            @endif

        </div>
    @endif
</div>
