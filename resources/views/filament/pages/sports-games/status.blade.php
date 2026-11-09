{{-- A game's state: the live badge and clock, final or postponed. --}}
@if ($game['state'] === 'live')
    <x-filament::badge color="danger">{{ __('LIVE') }}</x-filament::badge>
    @if ($game['status_detail'] !== '')
        <span
            class="min-w-0 truncate text-xs text-gray-600 dark:text-gray-300"
            title="{{ $game['status_detail'] }}"
        >{{ $game['status_detail'] }}</span>
    @endif
@elseif ($game['state'] === 'finished')
    <span class="text-sm font-bold text-gray-950 dark:text-white">{{ __('Final') }}</span>
@elseif ($game['state'] === 'postponed')
    <x-filament::badge color="warning">{{ __('Postponed') }}</x-filament::badge>
@else
    <span class="text-sm font-bold text-gray-950 dark:text-white">{{ \App\Services\Sports\SportsGameDisplay::localStart($game)->translatedFormat('g:i A') }}</span>
@endif
