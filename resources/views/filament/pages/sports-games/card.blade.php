@php
    use App\Services\Sports\SportsGameDisplay;

    $channelCount = count($this->channels[$game['league_id'].':'.$game['id']] ?? []);
    $sides = array_filter(SportsGameDisplay::sideOrder($game), fn (string $side): bool => isset($game[$side]));
@endphp
<button
    type="button"
    wire:key="sports-game-{{ $game['league_id'] }}-{{ $game['id'] }}"
    wire:click="mountAction('gameDetails', {{ \Illuminate\Support\Js::from(['league' => $game['league_id'], 'fixture' => $game['id']]) }})"
    style="{{ SportsGameDisplay::washStyle($game) }}"
    class="hover:ring-primary-500 focus-visible:ring-primary-500 flex w-full flex-col gap-3 rounded-xl bg-white p-4 text-start shadow-sm ring-1 ring-gray-950/5 transition focus-visible:ring-2 focus-visible:outline-none dark:bg-gray-900 dark:ring-white/10"
>
    {{-- Everything that loads in late sits on this row, whose height is fixed, so cards keep their size --}}
    <div class="flex min-h-7 w-full items-center gap-2">
        @include('filament.pages.sports-games.status', ['game' => $game])
        <div class="ms-auto flex shrink-0 items-center gap-2">
            @if (SportsGameDisplay::isWatchable($game) && $channelsLoaded)
                <x-filament::badge
                    :color="$channelCount > 0 ? 'primary' : 'gray'"
                    :icon="$channelCount > 0 ? 'heroicon-m-tv' : 'heroicon-m-no-symbol'"
                >
                    {{ $channelCount > 0 ? trans_choice('{1} :count channel|[2,*] :count channels', $channelCount, ['count' => $channelCount]) : __('No channel') }}
                </x-filament::badge>
            @endif
            @if ($showLeague)
                <span class="flex items-center gap-1.5 text-xs text-gray-600 dark:text-gray-300">
                    @include('filament.pages.sports-games.league-logo', ['url' => $game['league_logo'] ?? null, 'class' => 'h-5 w-5'])
                    {{ $this->leagueNames[$game['league_id']] ?? '' }}
                </span>
            @endif
        </div>
    </div>

    <div class="w-full space-y-2">
        @foreach ($sides as $side)
            <div class="flex items-center gap-3">
                @include('filament.pages.sports-games.crest', ['url' => $game[$side]['team']['logo'] ?? null, 'class' => 'h-12 w-12 shrink-0'])
                <span
                    @class([
                        'min-w-0 flex-1 truncate text-sm text-gray-950 dark:text-white',
                        'font-bold' => $game[$side]['winner'] ?? false,
                        'font-medium' => ! ($game[$side]['winner'] ?? false),
                    ])
                >{{ $game[$side]['team']['display_name'] }}</span>
                @if (SportsGameDisplay::isScored($game))
                    <span class="text-sm font-semibold text-gray-950 tabular-nums dark:text-white">{{ $game[$side]['score'] ?? '' }}</span>
                @endif
            </div>
        @endforeach
    </div>
</button>
