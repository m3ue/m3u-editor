@php
    $json = json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    // @js() isn't compiled inside a Blade component tag's attribute value
    // (Blade leaves raw "@word(...)" alone there so it doesn't collide with
    // Alpine's "@click" shorthand), so the JS-safe literal is built here and
    // interpolated via {{ }} instead — Js::from() already escapes quotes,
    // angle brackets, etc. as \uXXXX, so it's already double-escaping-safe.
    $jsonJs = \Illuminate\Support\Js::from($json);
@endphp

<div x-data="{ copied: false }" class="space-y-2">
    <div class="flex justify-end">
        <x-filament::icon-button
            icon="heroicon-o-clipboard-document"
            color="gray"
            size="sm"
            :label="__('Copy')"
            x-on:click="
                navigator.clipboard.writeText({{ $jsonJs }}).then(() => {
                    copied = true;
                    setTimeout(() => copied = false, 1500);
                });
            "
        />
    </div>

    <p x-show="copied" x-transition x-cloak class="text-xs text-success-600 dark:text-success-400">
        {{ __('Copied!') }}
    </p>

    <pre class="max-h-[65vh] overflow-auto rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">{{ $json }}</pre>
</div>
