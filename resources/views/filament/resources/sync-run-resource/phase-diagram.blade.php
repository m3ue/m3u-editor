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
            x-data="{
                src: @js($source),
                panZoom: null,
                zoomIn()    { this.panZoom?.zoomIn(); },
                zoomOut()   { this.panZoom?.zoomOut(); },
                resetView() { this.panZoom?.resetZoom(); this.panZoom?.resetPan(); },
            }"
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

                        const svgEl = $refs.target.querySelector('svg');
                        if (svgEl) {
                            svgEl.setAttribute('width', '100%');
                            svgEl.setAttribute('height', '100%');
                            svgEl.style.maxWidth = 'none';

                            if (!window.__svgPanZoom) {
                                await new Promise((resolve, reject) => {
                                    const s = document.createElement('script');
                                    s.src = 'https://cdn.jsdelivr.net/npm/svg-pan-zoom@3.6.1/dist/svg-pan-zoom.min.js';
                                    s.onload = resolve;
                                    s.onerror = reject;
                                    document.head.appendChild(s);
                                });
                                window.__svgPanZoom = window.svgPanZoom;
                            }

                            panZoom = window.__svgPanZoom(svgEl, {
                                zoomEnabled: true,
                                controlIconsEnabled: false,
                                fit: true,
                                center: true,
                                minZoom: 0.05,
                                maxZoom: 20,
                                zoomScaleSensitivity: 0.3,
                                preventMouseEventsDefault: true,
                            });
                        }
                    } catch (e) {
                        $refs.target.innerHTML = '<pre class=&quot;text-xs text-red-600&quot;>' + (e?.message || e) + '</pre>';
                    }
                };
                render();
            "
            class="rounded-md border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-3"
        >
            {{-- Controls --}}
            <div class="flex justify-end gap-1.5 mb-2">
                <button
                    @click="zoomIn()"
                    title="{{ __('Zoom in') }}"
                    class="inline-flex items-center justify-center w-7 h-7 rounded text-sm font-mono bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
                >+</button>
                <button
                    @click="zoomOut()"
                    title="{{ __('Zoom out') }}"
                    class="inline-flex items-center justify-center w-7 h-7 rounded text-sm font-mono bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
                >−</button>
                <button
                    @click="resetView()"
                    title="{{ __('Reset view') }}"
                    class="inline-flex items-center justify-center px-2 h-7 rounded text-xs bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors"
                >{{ __('Reset') }}</button>
            </div>

            {{-- Diagram viewport --}}
            <div
                x-ref="target"
                class="h-72 sm:h-96 overflow-hidden touch-none cursor-grab active:cursor-grabbing"
            >
                <p class="text-xs text-gray-500 dark:text-gray-400">{{ __('Loading diagram…') }}</p>
            </div>
        </div>
    @endif
</div>
