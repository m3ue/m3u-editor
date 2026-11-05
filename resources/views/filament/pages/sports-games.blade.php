<x-filament-panels::page>
    <div class="space-y-5" wire:init="loadGames">
        {{ $this->filtersForm }}

        @if (! $playlistId)
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Create a playlist first, then come back here to see which of its channels show each game.') }}
                </div>
            </x-filament::section>
        @elseif (! $this->hasLeagues())
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">
                    {{ __('Choose some leagues to see their games.') }}
                </div>
            </x-filament::section>
        @elseif (! $gamesLoaded)
            <div class="flex justify-center py-12">
                <x-filament::loading-indicator class="h-5 w-5" />
            </div>
        @elseif (count($this->games) === 0)
            <x-filament::section>
                <div class="text-sm text-gray-500 dark:text-gray-400">{{ __('No games on this day') }}</div>
            </x-filament::section>
        @else
            @if (count($this->gameGroups['live']) > 0)
                {{-- Scores change while a game is on; nothing else here does --}}
                <div wire:poll.30s.visible>
                    <h2 class="mb-3 text-base font-semibold text-gray-950 dark:text-white">{{ __('Live Now') }}</h2>
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($this->gameGroups['live'] as $game)
                            @include('filament.pages.sports-games.card', ['game' => $game, 'showLeague' => true])
                        @endforeach
                    </div>
                </div>
            @endif

            @foreach ($this->gameGroups['leagues'] as $group)
                <div wire:key="sports-league-{{ $loop->index }}-{{ $group['name'] }}">
                    <h2 class="mb-3 text-base font-semibold text-gray-950 dark:text-white">{{ $group['name'] }}</h2>
                    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($group['games'] as $game)
                            @include('filament.pages.sports-games.card', ['game' => $game, 'showLeague' => false])
                        @endforeach
                    </div>
                </div>
            @endforeach
        @endif
    </div>
</x-filament-panels::page>
