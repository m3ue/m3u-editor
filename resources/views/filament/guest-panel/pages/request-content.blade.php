<x-filament-panels::page>
    @if($this->integration)
        <x-filament::section>
            <x-slot name="heading">
                {{ __('Search & Request') }}
            </x-slot>
            <x-slot name="description">
                {{ __('Request TV shows or movies to be added to :playlist via :integration.', [
                    'playlist' => $playlistName ?? 'your playlist',
                    'integration' => $this->integration->name,
                ]) }}
            </x-slot>

            {{-- For multiple integrations, render one ArrSearch per integration. --}}
            @if($this->integrations->count() > 1)
                <div x-data="{ active: {{ $this->integrations->first()->id }} }">
                    <div class="mb-4 flex flex-wrap gap-2">
                        @foreach($this->integrations as $integrationOption)
                            <button
                                type="button"
                                @click="active = {{ $integrationOption->id }}"
                                :class="active === {{ $integrationOption->id }} ? 'bg-primary-600 text-white' : 'bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300'"
                                class="px-3 py-1.5 rounded-md text-sm font-medium transition-colors"
                            >
                                {{ $integrationOption->name }}
                            </button>
                        @endforeach
                    </div>

                    @foreach($this->integrations as $integrationOption)
                        <div x-show="active === {{ $integrationOption->id }}" x-cloak>
                            <livewire:arr-search
                                :integration-id="$integrationOption->id"
                                :guest-mode="true"
                                :wire:key="'arr-search-'.$integrationOption->id"
                            />
                        </div>
                    @endforeach
                </div>
            @else
                <livewire:arr-search
                    :integration-id="$this->integration->id"
                    :guest-mode="true"
                    :wire:key="'arr-search-'.$this->integration->id"
                />
            @endif
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
