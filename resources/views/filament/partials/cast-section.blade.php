{{--
    Rich cast row (TMDB / media-server `cast_list`), shared by the VOD and
    Series view pages. Expects $cast as an array of
    ['id' => int, 'name' => string, 'character' => ?string, 'photo' => ?string].
    Renders nothing when there is no rich cast.

    When $filmographyPage is passed, each tile is wrapped in an <a> that
    navigates to the ActorFilmography page for that actor, optionally
    scoped to $playlistId (admin) or $playlistUuid (guest) so the page shows
    only films/shows available in that playlist.
--}}
@php
    $castMembers = collect($cast ?? [])
        ->filter(fn ($member) => is_array($member) && filled($member['name'] ?? null))
        ->values();
    $collapsed ??= false;
    $currentPanel = \Filament\Facades\Filament::getCurrentPanel();
    $isGuestPanel = $currentPanel !== null && $currentPanel->getId() !== 'admin';
    $filmographyPageClass = $filmographyPage ?? null;
@endphp

@if ($castMembers->isNotEmpty())
    <div class="mb-6">
        <x-filament::section icon="heroicon-o-users" :heading="__('Cast')" collapsible :collapsed="$collapsed">
            <x-slot name="afterHeader">
                <x-filament::badge color="gray">{{ $castMembers->count() }}</x-filament::badge>
            </x-slot>

            <div class="flex gap-4 overflow-x-auto pb-2">
                @foreach ($castMembers as $member)
                    @php
                        $personId = (int) ($member['id'] ?? 0);
                        $actorName = (string) $member['name'];
                        $character = (string) ($member['character'] ?? '');
                        $photo = $member['photo'] ?? null;

                        $tileInner = '
                            <div class="fi-avatar fi-circular fi-size-lg flex h-24 w-24 items-center justify-center overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">'
                            .($photo
                                ? '<x-filament::avatar src="'.e($photo).'" alt="'.e($actorName).'" size="lg" />'
                                : '<x-filament::icon icon="heroicon-o-user" class="h-10 w-10 text-gray-400" />')
                            .'</div>
                            <div class="mt-2 line-clamp-2 text-sm font-medium text-gray-900 dark:text-gray-100">'.e($actorName).'</div>'
                            .($character !== ''
                                ? '<div class="line-clamp-2 text-xs text-gray-500 dark:text-gray-400">'.e($character).'</div>'
                                : '');
                    @endphp

                    @if ($filmographyPageClass && $personId > 0)
                        @php
                            $filmographyParams = array_filter([
                                'personId' => $personId,
                                'name' => $actorName,
                                'playlistId' => $isGuestPanel ? null : ($playlistId ?? null),
                                'playlistUuid' => $isGuestPanel ? ($playlistUuid ?? null) : null,
                            ], fn ($v) => $v !== null && $v !== '');
                            $filmographyUrl = $filmographyPageClass::getUrl($filmographyParams);
                        @endphp
                        <a
                            href="{{ $filmographyUrl }}"
                            class="group flex w-24 flex-shrink-0 flex-col items-center text-center transition-opacity hover:opacity-80"
                            title="{{ __('View filmography') }} — {{ $actorName }}"
                        >
                            {!! $tileInner !!}
                        </a>
                    @else
                        <div class="flex w-24 flex-shrink-0 flex-col items-center text-center">{!! $tileInner !!}</div>
                    @endif
                @endforeach
            </div>
        </x-filament::section>
    </div>
@endif
