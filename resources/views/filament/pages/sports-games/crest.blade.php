{{-- A team crest (or league logo), or a neutral shield when there is none. --}}
@if (! empty($url))
    <img src="{{ $url }}" alt="" loading="lazy" class="{{ $class }} object-contain" />
@else
    <x-filament::icon icon="heroicon-o-shield-check" class="{{ $class }} text-gray-400 dark:text-gray-500" />
@endif
