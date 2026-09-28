{{--
    Rich cast row (TMDB / media-server `cast_list`), shared by the VOD and
    Series view pages. Expects $cast as an array of
    ['name' => string, 'character' => ?string, 'photo' => ?string, 'id' => ?int].
    Renders nothing when there is no rich cast.

    When $filmographyPage is passed, each tile is wrapped in an <a> that
    navigates to the ActorFilmography page for that actor, optionally
    scoped to $playlistId (the guest ActorFilmography page derives its own
    playlist scope from the route/session instead, so it has no equivalent
    param here) so the page shows only films/shows available in that playlist.
--}}
@php
    $castMembers = collect($cast ?? [])
        ->filter(fn ($member) => is_array($member) && filled($member['name'] ?? null))
        ->values();
    $collapsed ??= false;
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
                        $tileClasses = 'flex w-24 flex-shrink-0 flex-col items-center text-center';
                    @endphp

                    @if ($filmographyPageClass && $personId > 0)
                        @php
                            $filmographyParams = array_filter([
                                'personId' => $personId,
                                'name' => $actorName,
                                'playlistId' => $playlistId ?? null,
                            ], fn ($v) => $v !== null && $v !== '');
                            $filmographyUrl = $filmographyPageClass::getUrl($filmographyParams);
                        @endphp
                        <a
                            href="{{ $filmographyUrl }}"
                            class="group {{ $tileClasses }} transition-opacity hover:opacity-80"
                            title="{{ __('View filmography') }} - {{ $actorName }}"
                        >
                            @include('filament.partials.cast-section-tile', [
                                'photo' => $photo,
                                'actorName' => $actorName,
                                'character' => $character,
                            ])
                        </a>
                    @else
                        <div class="{{ $tileClasses }}">
                            @include('filament.partials.cast-section-tile', [
                                'photo' => $photo,
                                'actorName' => $actorName,
                                'character' => $character,
                            ])
                        </div>
                    @endif
                @endforeach
            </div>
        </x-filament::section>
    </div>
@endif
