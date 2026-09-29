{{-- Content rating plus studio/network names, shared by the VOD and series view pages. --}}
@props(['rating' => null, 'names' => [], 'icon'])

@php
    $names = collect($names);
@endphp

@if ($rating || $names->isNotEmpty())
    <div class="flex flex-wrap items-center gap-2">
        @if ($rating)
            <x-filament::badge color="gray">{{ $rating }}</x-filament::badge>
        @endif
        @foreach ($names as $name)
            <x-filament::badge color="gray" :icon="$icon">{{ $name }}</x-filament::badge>
        @endforeach
    </div>
@endif
