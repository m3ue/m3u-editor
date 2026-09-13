{{--
    Single tile body shared by cast-section.blade.php — avatar image (or
    user-icon placeholder) + actor name + character. Renders nothing
    besides the body; the parent controls the link wrapper.
--}}
<div class="fi-avatar fi-circular fi-size-lg flex h-24 w-24 items-center justify-center overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700">
    @if (! empty($photo))
        <x-filament::avatar :src="$photo" :alt="$actorName" size="lg" />
    @else
        <x-filament::icon icon="heroicon-o-user" class="h-10 w-10 text-gray-400" />
    @endif
</div>
<div class="mt-2 line-clamp-2 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $actorName }}</div>
@if (! empty($character))
    <div class="line-clamp-2 text-xs text-gray-500 dark:text-gray-400">{{ $character }}</div>
@endif
