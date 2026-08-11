@php
    $json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
@endphp

<div class="space-y-2">
    @if ($itemsTotal > $itemsShown)
        <x-filament::callout icon="heroicon-o-information-circle" color="info">
            <x-slot name="heading">
                {{ __('Showing :shown of :total items', ['shown' => $itemsShown, 'total' => $itemsTotal]) }}
            </x-slot>

            <x-slot name="description">
                {{ __('The full catalog (all :total items) is what actually gets synced — this preview is capped so a large library does not crash the browser.', ['total' => $itemsTotal]) }}
            </x-slot>
        </x-filament::callout>
    @endif

    <pre class="max-h-[65vh] overflow-auto rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">{{ $json }}</pre>
</div>
