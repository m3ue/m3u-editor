@php
    use App\Services\LogoService;
    use App\Services\Sports\SportsGameDisplay;
@endphp
@if ($game === null)
    <div class="py-8 text-center text-sm text-gray-500 dark:text-gray-400">
        {{ __('No more details are available for this game yet.') }}
    </div>
@else
    @php
        $sides = SportsGameDisplay::sideOrder($game);
        [$firstSide, $secondSide] = $sides;
        $isUpcoming = $game['state'] === 'scheduled';
        $barColors = SportsGameDisplay::barColors($game);
        $info = $details['info'] ?? [];
        $venue = implode(', ', array_filter([$info['venue'] ?? null, $info['location'] ?? null]));
        $panelClass = 'bg-white/70 dark:bg-gray-900/70';
        $mutedClass = 'text-xs text-gray-600 dark:text-gray-300';
        $start = SportsGameDisplay::localStart($game);
    @endphp
    {{-- Refreshes itself while the game is on; the page's poll may be off for a game not in its Live group --}}
    <div
        @if ($game['state'] === 'live') wire:poll.30s.visible @endif
        style="{{ SportsGameDisplay::washStyle($game) }}"
        class="space-y-5 rounded-xl p-4"
    >
        <div>
            <div class="mb-3 flex items-center justify-center gap-2">
                @if (! empty($game['league_logo']))
                    <img src="{{ $game['league_logo'] }}" alt="" loading="lazy" class="h-5 w-5 object-contain" />
                @endif
                <span class="text-sm font-medium text-gray-950 dark:text-white">{{ $leagueName }}</span>
            </div>
            <div class="grid grid-cols-3 items-start gap-2">
                @foreach ([$firstSide, null, $secondSide] as $side)
                    @if ($side === null)
                        <div class="flex flex-col items-center gap-1 pt-3 text-center">
                            @if ($isUpcoming)
                                <span class="text-2xl font-bold text-gray-950 tabular-nums dark:text-white">{{ $start->translatedFormat('g:i A') }}</span>
                            @else
                                @if (SportsGameDisplay::isScored($game))
                                    <span class="text-3xl font-bold text-gray-950 tabular-nums dark:text-white">{{ $game[$firstSide]['score'] ?? '-' }} - {{ $game[$secondSide]['score'] ?? '-' }}</span>
                                @endif
                                @include('filament.pages.sports-games.status', ['game' => $game])
                            @endif
                        </div>
                    @else
                        <div class="flex flex-col items-center gap-1 text-center">
                            @include('filament.pages.sports-games.crest', ['url' => $game[$side]['team']['logo'] ?? null, 'class' => 'h-16 w-16'])
                            <span class="text-sm font-semibold text-gray-950 dark:text-white">{{ $game[$side]['team']['display_name'] ?? '' }}</span>
                            @if (! empty($info[$side.'_record']))
                                <span class="{{ $mutedClass }}">{{ $info[$side.'_record'] }}</span>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>
        </div>

        @if ($showsChannels)
            <x-filament::section compact heading-tag="h3" :heading="__('On Your Channels')" :class="$panelClass">
                @if ($isFindingChannels)
                    <div class="flex justify-center py-2"><x-filament::loading-indicator class="h-5 w-5" /></div>
                @elseif ($channelRows === [])
                    <p class="{{ $mutedClass }}">{{ __('No channel in this playlist is showing this game.') }}</p>
                @else
                    <div class="space-y-2">
                        @foreach ($channelRows as $row)
                            <div class="flex items-center gap-3" wire:key="sports-channel-{{ $row['channel']->id }}">
                                <img
                                    src="{{ LogoService::getChannelLogoUrl($row['channel']) }}"
                                    alt=""
                                    loading="lazy"
                                    class="h-9 w-12 shrink-0 object-contain"
                                />
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="truncate text-sm font-medium text-gray-950 dark:text-white"
                                        title="{{ $row['channel']->display_title }}"
                                    >
                                        {{ $row['channel']->display_title }}
                                    </div>
                                    @php($channelDetail = implode(' · ', array_filter([$row['program_title'], $row['channel']->group])))
                                    @if ($channelDetail !== '')
                                        <div class="truncate {{ $mutedClass }}" title="{{ $channelDetail }}">
                                            {{ $channelDetail }}
                                        </div>
                                    @endif
                                </div>
                                <x-filament::icon-button
                                    icon="heroicon-m-play-circle"
                                    color="primary"
                                    size="xl"
                                    :label="__('Play')"
                                    wire:click="playChannel({{ $row['channel']->id }})"
                                />
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::section>
        @endif

        @if ($details === null)
            <p class="py-2 text-center text-sm text-gray-600 dark:text-gray-300">
                {{ __('No more details are available for this game yet.') }}
            </p>
        @else
            @if ($details['line_score'] !== null)
                <x-filament::section compact heading-tag="h3" :heading="__('Score by period')" :class="$panelClass">
                    <div class="overflow-x-auto">
                        <table class="text-sm text-gray-950 tabular-nums dark:text-white">
                            <thead>
                                <tr class="{{ $mutedClass }}">
                                    <th class="px-2 py-1"></th>
                                    @foreach ($details['line_score']['periods'] as $period)
                                        <th class="px-2 py-1 text-center font-medium">{{ $period }}</th>
                                    @endforeach
                                    <th class="px-2 py-1 text-center font-medium">{{ __('T') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($sides as $side)
                                    <tr>
                                        <td class="px-2 py-1 font-semibold">
                                            {{ ($game[$side]['team']['abbreviation'] ?? '') ?: ($game[$side]['team']['short_name'] ?? '') }}
                                        </td>
                                        @foreach ($details['line_score'][$side] as $score)
                                            <td class="px-2 py-1 text-center">{{ $score }}</td>
                                        @endforeach
                                        <td class="px-2 py-1 text-center font-bold">
                                            {{ $game[$side]['score'] ?? '-' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-filament::section>
            @endif

            @if ($details['events'] !== [])
                <x-filament::section compact heading-tag="h3" :heading="__('Key moments')" :class="$panelClass">
                    <div class="space-y-3">
                        @foreach ($details['events'] as $event)
                            <div class="flex items-start gap-2">
                                <span class="w-16 shrink-0 {{ $mutedClass }}">{{ $event['time'] }}</span>
                                <span class="flex w-5 shrink-0 justify-center pt-0.5">
                                    @switch ($event['kind'])
                                        @case ('yellow_card')
                                            <span class="h-4 w-3 rounded-sm bg-yellow-400"></span>
                                            @break
                                        @case ('red_card')
                                            <span class="bg-danger-500 h-4 w-3 rounded-sm"></span>
                                            @break
                                        @case ('substitution')
                                            <x-filament::icon
                                                icon="heroicon-m-arrows-right-left"
                                                class="h-4 w-4 text-gray-500 dark:text-gray-400"
                                            />
                                            @break
                                        @case ('penalty')
                                            <x-filament::icon
                                                icon="heroicon-m-scale"
                                                class="h-4 w-4 text-gray-500 dark:text-gray-400"
                                            />
                                            @break
                                        @default
                                            <x-filament::icon icon="heroicon-m-flag" class="text-primary-500 h-4 w-4" />
                                    @endswitch
                                </span>
                                @if ($event['side'] !== null)
                                    @include('filament.pages.sports-games.crest', ['url' => $game[$event['side']]['team']['logo'] ?? null, 'class' => 'h-5 w-5 shrink-0'])
                                @endif
                                <span class="min-w-0 flex-1 text-sm text-gray-950 dark:text-white">{{ $event['text'] }}</span>
                                @if ($event['home_score'] !== null && $event['away_score'] !== null)
                                    <span class="text-sm font-bold text-gray-950 tabular-nums dark:text-white">{{ $event[$firstSide.'_score'] }}-{{ $event[$secondSide.'_score'] }}</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            @if ($details['stats'] !== [])
                {{-- Before a game, the provider's numbers are its season so far --}}
                <x-filament::section
                    compact
                    heading-tag="h3"
                    :heading="$isUpcoming ? __('Season stats') : __('Team stats')"
                    :class="$panelClass"
                >
                    <div class="space-y-3">
                        @foreach ($details['stats'] as $stat)
                            @php($shares = SportsGameDisplay::shares($stat[$firstSide], $stat[$secondSide]))
                            <div>
                                <div class="mb-1 text-center {{ $mutedClass }}">{{ $stat['label'] }}</div>
                                <div class="flex items-center gap-3 text-sm font-semibold text-gray-950 tabular-nums dark:text-white">
                                    <span>{{ $stat[$firstSide] }}</span>
                                    <div class="flex h-1.5 min-w-0 flex-1 gap-0.5 overflow-hidden rounded-full bg-gray-950/10 dark:bg-white/10">
                                        @if ($shares !== null)
                                            <div style="width: {{ $shares[0] }}%; background-color: {{ $barColors[0] }};"></div>
                                            <div style="width: {{ $shares[1] }}%; background-color: {{ $barColors[1] }};"></div>
                                        @endif
                                    </div>
                                    <span>{{ $stat[$secondSide] }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif

            @if ($details['leaders'] !== [])
                <x-filament::section
                    compact
                    heading-tag="h3"
                    :heading="$isUpcoming ? __('Season leaders') : __('Top performers')"
                    :class="$panelClass"
                >
                    <div class="space-y-4">
                        @foreach ($details['leaders'] as $category)
                            <div>
                                <div class="mb-2 {{ $mutedClass }}">{{ $category['label'] }}</div>
                                <div class="grid grid-cols-2 gap-3">
                                    @foreach ($sides as $side)
                                        <div class="flex items-start gap-2">
                                            @if ($category[$side] !== null)
                                                @php($ring = SportsGameDisplay::teamColor($game[$side]['team'] ?? null))
                                                {{-- Headshots are cut-outs, so the team's colour fills in behind them --}}
                                                <div
                                                    class="flex h-12 w-12 shrink-0 items-center justify-center overflow-hidden rounded-full border-2 border-gray-300 bg-gray-950/10 dark:border-gray-600 dark:bg-white/10"
                                                    @if ($ring !== null) style="border-color: rgb({{ implode(' ', $ring) }}); background-color: rgb({{ implode(' ', $ring) }} / 0.2);" @endif
                                                >
                                                    @if (! empty($category[$side]['photo']))
                                                        <img
                                                            src="{{ $category[$side]['photo'] }}"
                                                            alt=""
                                                            loading="lazy"
                                                            class="h-full w-full object-cover"
                                                        />
                                                    @else
                                                        <x-filament::icon
                                                            icon="heroicon-m-user"
                                                            class="h-6 w-6 text-gray-500 dark:text-gray-400"
                                                        />
                                                    @endif
                                                </div>
                                                <div class="min-w-0">
                                                    <div class="text-sm font-semibold text-gray-950 dark:text-white">
                                                        {{ $category[$side]['name'] }}
                                                    </div>
                                                    @if ($category[$side]['value'] !== null)
                                                        <div class="{{ $mutedClass }}">
                                                            {{ $category[$side]['value'] }}
                                                        </div>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-filament::section>
            @endif
        @endif

        <x-filament::section compact heading-tag="h3" :heading="__('Game info')" :class="$panelClass">
            <dl class="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-2 text-sm text-gray-950 dark:text-white">
                <dt class="{{ $mutedClass }}">{{ __('Start time') }}</dt>
                <dd>{{ $start->translatedFormat('D, M j, Y g:i A') }}</dd>
                @if ($venue !== '')
                    <dt class="{{ $mutedClass }}">{{ __('Venue') }}</dt>
                    <dd>{{ $venue }}</dd>
                @endif
                @if (! empty($info['attendance']))
                    <dt class="{{ $mutedClass }}">{{ __('Attendance') }}</dt>
                    <dd>{{ number_format($info['attendance']) }}</dd>
                @endif
                @if (! empty($game['broadcasts']))
                    <dt class="{{ $mutedClass }}">{{ __('Broadcast') }}</dt>
                    <dd>{{ implode(', ', $game['broadcasts']) }}</dd>
                @endif
                @if (! empty($info['series']))
                    <dt class="{{ $mutedClass }}">{{ __('Series') }}</dt>
                    <dd>{{ $info['series'] }}</dd>
                @endif
                @if (! empty($info['officials']))
                    <dt class="{{ $mutedClass }}">{{ __('Officials') }}</dt>
                    <dd>{{ implode(', ', $info['officials']) }}</dd>
                @endif
            </dl>
        </x-filament::section>
    </div>
@endif
