@php
    /** @var \App\Models\SyncRun $record */
    $source = \App\Filament\Resources\SyncRuns\SyncRunResource::buildPhaseMermaid($record);
    $isRunning = ! $record->isFinished();
    $hash = md5($source);
@endphp

<div @if($isRunning) wire:poll.2s @endif>
    @if(empty($source))
        <p class="text-sm text-gray-500 dark:text-gray-400">
            {{ __('No pipeline available for this run.') }}
        </p>
    @else
        <div
            wire:key="mermaid-{{ $hash }}"
            wire:ignore
            x-data="{ src: @js($source) }"
            x-init="
                const render = async () => {
                    if (!window.__mermaid) {
                        const mod = await import('https://cdn.jsdelivr.net/npm/mermaid@11/dist/mermaid.esm.min.mjs');
                        window.__mermaid = mod.default;
                        window.__mermaid.initialize({ startOnLoad: false, securityLevel: 'loose', theme: 'default' });
                    }
                    const id = 'mermaid-svg-{{ $hash }}';
                    try {
                        const { svg } = await window.__mermaid.render(id, src);
                        $refs.target.innerHTML = svg;
                    } catch (e) {
                        $refs.target.innerHTML = '<pre class=&quot;text-xs text-red-600&quot;>' + (e?.message || e) + '</pre>';
                    }
                };
                render();
            "
            class="overflow-x-auto rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3"
        >
            <div x-ref="target" class="min-h-12">
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Loading diagram…') }}</p>
            </div>
        </div>
    @endif
</div>
