{{--
    Reusable cast avatar grid used by view-series and view-vod pages.

    Each cast member renders as a vertical tile: avatar on top, actor name
    then character below. The tile is a link to the ActorFilmography page
    filtered to the originating playlist, so the next page only shows items
    available in that playlist.

    Layout matches the project's existing cast display reference: the link
    container is `flex w-24 flex-shrink-0 flex-col items-center text-center`,
    the avatar uses `<x-filament::avatar size="lg">` which emits the
    `fi-avatar fi-circular fi-size-lg` classes, and name/character sit below
    the avatar as separate line-clamped text blocks.

    Expected data:
      $castMembers       array<array{id:int, actor:string, character:string, photo:?string}>
      $filmographyPage   string  Fully-qualified ActorFilmography / GuestActorFilmography class
      $playlistId        int|null  Admin: pass the originating playlist_id
      $playlistUuid      string|null  Guest: pass the originating playlist uuid

    Render is empty when $castMembers is empty. Caller is responsible for the
    surrounding <x-filament::section>.
--}}
@if (! empty($castMembers))
    <div class="grid grid-cols-3 gap-4 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 xl:grid-cols-8">
        @foreach ($castMembers as $member)
            @php
                $personId = (int) ($member['id'] ?? 0);
                $actorName = (string) ($member['actor'] ?? '');
                $character = (string) ($member['character'] ?? '');
                $photo = $member['photo'] ?? null;
                $filmographyParams = array_filter([
                    'personId' => $personId,
                    'name' => $actorName,
                    'playlistId' => $playlistId ?? null,
                    'playlistUuid' => $playlistUuid ?? null,
                ], fn ($v) => $v !== null && $v !== '');
                $filmographyUrl = $filmographyPage::getUrl($filmographyParams);
            @endphp
            <a
                href="{{ $filmographyUrl }}"
                class="group flex w-24 flex-shrink-0 flex-col items-center text-center transition-opacity hover:opacity-80"
                title="{{ __('View filmography') }} — {{ $actorName }}"
            >
                @if (! empty($photo))
                    <x-filament::avatar :src="$photo" :alt="$actorName" size="lg" />
                @else
                    <div class="flex h-24 w-24 items-center justify-center rounded-full bg-gray-200 dark:bg-gray-700">
                        <x-heroicon-o-user class="h-10 w-10 text-gray-400" />
                    </div>
                @endif
                <div class="group-hover:text-primary-600 dark:group-hover:text-primary-400 mt-2 line-clamp-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ $actorName }}
                </div>
                @if ($character !== '')
                    <div class="line-clamp-2 text-xs text-gray-500 dark:text-gray-400">{{ $character }}</div>
                @endif
            </a>
        @endforeach
    </div>
@endif
