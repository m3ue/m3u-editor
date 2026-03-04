@php
    $position = $position ?? 'right';
    $positionClasses = match ($position) {
        'left' => 'right-full mr-2 top-1/2 -translate-y-1/2',
        'top' => 'bottom-full mb-2 left-1/2 -translate-x-1/2',
        'bottom' => 'top-full mt-2 left-1/2 -translate-x-1/2',
        default => 'left-full ml-2 top-1/2 -translate-y-1/2', // right
    };
@endphp
<div x-data="{ copied: false }" class="relative">
    <button type="button" class="text-gray-400 hover:text-primary-600" x-on:click="
            if (window.navigator && window.navigator.clipboard && window.navigator.clipboard.writeText) {
                window.navigator.clipboard.writeText('{{ $text }}')
                    .then(() => { copied = true; setTimeout(() => copied = false, 1500); });
            } else {
                // fallback for unsupported browsers
                const el = document.createElement('textarea');
                el.value = '{{ $text }}';
                document.body.appendChild(el);
                el.select();
                document.execCommand('copy');
                document.body.removeChild(el);
                copied = true; setTimeout(() => copied = false, 1500);
            }
        " aria-label="Copy to clipboard">
        <x-heroicon-s-clipboard-document-check class="w-5 h-5" />
    </button>
    <span x-show="copied" x-transition
        class="absolute {{ $positionClasses }} bg-gray-800 text-white text-xs rounded px-2 py-1 shadow whitespace-nowrap"
        style="display: none;">
        Copied!
    </span>
</div>