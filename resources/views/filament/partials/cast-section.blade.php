{{--
    Rich cast row (TMDB / media-server `cast_list`), shared by the VOD and
    Series view pages. Expects $cast as an array of
    ['name' => string, 'character' => ?string, 'photo' => ?string].
    Renders nothing when there is no rich cast.
--}}
@php
    $castMembers = collect($cast ?? [])
        ->filter(fn ($member) => is_array($member) && filled($member['name'] ?? null))
        ->values();
    $collapsed ??= false;
@endphp

@if ($castMembers->isNotEmpty())
    <div class="mb-6">
        <x-filament::section icon="heroicon-o-users" :heading="__('Cast')" collapsible :collapsed="$collapsed">
            <x-slot name="afterHeader">
                <x-filament::badge color="gray">{{ $castMembers->count() }}</x-filament::badge>
            </x-slot>

            <div class="flex gap-4 overflow-x-auto pb-2">
                @foreach ($castMembers as $member)
                    <div class="flex w-24 flex-shrink-0 flex-col items-center text-center">
                        @if (! empty($member['photo']))
                            <x-filament::avatar :src="$member['photo']" :alt="$member['name']" size="lg" />
                        @else
                            <div class="fi-avatar fi-circular flex h-12 w-12 items-center justify-center bg-gray-100 dark:bg-gray-800">
                                <x-filament::icon icon="heroicon-o-user" class="h-6 w-6 text-gray-400" />
                            </div>
                        @endif

                        <div class="mt-2 line-clamp-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                            {{ $member['name'] }}
                        </div>

                        @if (! empty($member['character']))
                            <div class="line-clamp-2 text-xs text-gray-500 dark:text-gray-400">
                                {{ $member['character'] }}
                            </div>
                        @endif
                    </div>
                @endforeach
            </div>
        </x-filament::section>
    </div>
@endif
