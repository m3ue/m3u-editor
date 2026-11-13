{{-- "DVR" badge for library items that came from a DVR recording, shared by the VOD and series view pages. --}}
@props(['recording' => null])

<x-filament::badge
    color="danger"
    icon="heroicon-m-video-camera"
    :tooltip="$recording?->recordedNote() ?? __('Added to the library from a DVR recording')"
>
    {{ __('DVR') }}
</x-filament::badge>
