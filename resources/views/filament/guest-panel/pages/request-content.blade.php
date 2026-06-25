<x-filament-panels::page>
    @if($this->playlistAuth)
        <x-filament::section collapsible collapse-id="guest-my-requests" persist-collapsed>
            <x-slot name="heading">{{ __('My Requests') }}</x-slot>
            <x-slot name="description">{{ __('Track the status and download progress of your content requests.') }}</x-slot>

            <livewire:guest-queue-status wire:key="guest-queue-status" />
        </x-filament::section>
    @endif

    @if($this->integrations->isNotEmpty())
        <x-filament::section>
            <x-slot name="heading">
                {{ __('Search & Request') }}
            </x-slot>
            <x-slot name="description">
                {{ __('Request TV shows or movies to be added to :playlist.', [
                    'playlist' => $playlistName ?? 'your playlist',
                ]) }}
            </x-slot>

            <livewire:arr-search
                :guest-integration-ids="$this->integrations->pluck('id')->all()"
                :guest-mode="true"
                :playlist-auth-id="$this->playlistAuth?->id"
                wire:key="arr-search-guest"
            />
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <x-heroicon-o-magnifying-glass-circle class="w-12 h-12 text-gray-300 dark:text-gray-600" />
                <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ __('No content request integration is configured for this playlist') }}
                </h4>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Ask the playlist owner to enable Sonarr or Radarr for guest requests.') }}
                </p>
            </div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
