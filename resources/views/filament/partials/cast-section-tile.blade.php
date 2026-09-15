{{--
    Single tile body shared by cast-section.blade.php - avatar image (or
    user-icon placeholder) + actor name + character. Renders nothing
    besides the body; the parent controls the link wrapper.
--}}
@if (! empty($photo))
    <x-filament::avatar :src="$photo" :alt="$actorName" size="lg" />
@else
    <div class="fi-avatar fi-circular flex h-12 w-12 items-center justify-center bg-gray-100 dark:bg-gray-800">
        <x-filament::icon icon="heroicon-o-user" class="h-6 w-6 text-gray-400" />
    </div>
@endif
<div class="mt-2 line-clamp-2 text-sm font-medium text-gray-900 dark:text-gray-100">{{ $actorName }}</div>
@if (! empty($character))
    <div class="line-clamp-2 text-xs text-gray-500 dark:text-gray-400">{{ $character }}</div>
@endif
