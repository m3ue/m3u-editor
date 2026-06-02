<div class="space-y-4 p-1">
    {{-- Pattern --}}
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            Regex Pattern
        </label>
        <input
            type="text"
            wire:model="pattern"
            placeholder="e.g. ^(US|UK|CA):\s*"
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-sm text-gray-900 shadow-sm placeholder-gray-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:placeholder-gray-500"
        />
        <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
            Do not include delimiters (e.g. write <code class="font-mono">pattern</code>, not <code class="font-mono">/pattern/</code>).
            Flags used: <code class="font-mono">{{ $flags }}</code>.
        </p>
    </div>

    {{-- Replacement --}}
    <div>
        <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
            Replace with <span class="text-gray-400 dark:text-gray-500 font-normal">(optional)</span>
        </label>
        <input
            type="text"
            wire:model="replacement"
            placeholder="Leave empty to test match-only"
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-sm text-gray-900 shadow-sm placeholder-gray-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:placeholder-gray-500"
        />
    </div>

    {{-- Samples --}}
    <div>
        <div class="flex items-center justify-between mb-1">
            <label class="block text-sm font-medium text-gray-700 dark:text-gray-300">
                Sample data <span class="text-gray-400 dark:text-gray-500 font-normal">(one per line)</span>
            </label>
            @if($samplesContext)
                <div class="flex items-center gap-1">
                    @if(trim($samples) !== '')
                        <x-filament::icon-button
                            wire:click="loadSamples"
                            wire:loading.attr="disabled"
                            icon="heroicon-o-arrow-path"
                            size="xs"
                            color="gray"
                            label="Refresh samples"
                            wire:loading.class="animate-spin"
                            wire:target="loadSamples"
                        />
                    @else
                        <x-filament::button
                            wire:click="loadSamples"
                            wire:loading.attr="disabled"
                            color="gray"
                            size="xs"
                            icon="heroicon-o-arrow-down-tray"
                        >
                            <span wire:loading.remove wire:target="loadSamples">Load real samples</span>
                            <span wire:loading wire:target="loadSamples">Loading&hellip;</span>
                        </x-filament::button>
                    @endif
                </div>
            @endif
        </div>
        <textarea
            wire:model="samples"
            rows="6"
            placeholder="Paste sample values here, one per line&#10;e.g.&#10;US: BBC One HD&#10;UK: Sky News&#10;Sport FHD"
            class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 font-mono text-sm text-gray-900 shadow-sm placeholder-gray-400 focus:border-primary-500 focus:outline-none focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 dark:placeholder-gray-500 resize-y"
        ></textarea>
    </div>

    {{-- Test button --}}
    <div class="flex items-center gap-3">
        <x-filament::button
            wire:click="test"
            wire:loading.attr="disabled"
            color="primary"
            icon="heroicon-o-play"
        >
            <span wire:loading.remove wire:target="test">Run Test</span>
            <span wire:loading wire:target="test">Running&hellip;</span>
        </x-filament::button>

        @if($tested && empty($results))
            @if(trim($pattern) === '')
                <span class="text-sm text-amber-600 dark:text-amber-400">
                    Enter a pattern above.
                </span>
            @else
                <span class="text-sm text-gray-500 dark:text-gray-400">
                    No samples to test against.
                </span>
            @endif
        @endif
    </div>

    {{-- Results --}}
    @if($tested && !empty($results))
        {!! $this->getRenderedResults() !!}
    @endif
</div>
