<x-filament-panels::page>
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
