<x-filament-widgets::widget wire:poll.5s.visible>
    @if (!empty($batches) || !empty($running_jobs))
        <x-filament::section :heading="__('Live Queue')">
            <div class="space-y-2">
                {{-- Active batches --}}
                @foreach ($batches as $batch)
                    @php $failing = $batch['status'] === 'failing'; @endphp
                    <div
                        class="relative rounded-lg border px-3 pt-3 pb-2 {{ $failing ? 'border-danger-200 bg-danger-50 dark:border-danger-800 dark:bg-danger-950/30' : 'border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5' }}">

                        {{-- Action buttons — top right --}}
                        <div class="absolute top-2 right-2 flex items-center gap-1">
                            @if ($failing)
                                <button type="button" wire:click="retryBatch('{{ $batch['id'] }}')"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium text-warning-700 hover:bg-warning-100 dark:text-warning-400 dark:hover:bg-warning-950/60"
                                    title="{{ __('Retry failed') }}">
                                    <x-heroicon-m-arrow-path class="h-3.5 w-3.5" />
                                    {{ __('Retry') }}
                                </button>
                            @endif

                            <button type="button" wire:click="cancelBatch('{{ $batch['id'] }}')"
                                wire:confirm="{{ __('Cancel this batch? Running jobs will complete but no new jobs will start.') }}"
                                wire:loading.attr="disabled"
                                class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium text-gray-500 hover:bg-gray-200 dark:text-gray-400 dark:hover:bg-white/10"
                                title="{{ __('Cancel') }}">
                                <x-heroicon-m-x-mark class="h-3.5 w-3.5" />
                                {{ __('Cancel') }}
                            </button>

                            @if ($failing)
                                <button type="button" wire:click="dismissBatch('{{ $batch['id'] }}')"
                                    wire:confirm="{{ __('Dismiss this batch? It will be cancelled and removed from the history.') }}"
                                    wire:loading.attr="disabled"
                                    class="inline-flex items-center gap-1 rounded px-1.5 py-0.5 text-xs font-medium text-danger-600 hover:bg-danger-100 dark:text-danger-400 dark:hover:bg-danger-950/60"
                                    title="{{ __('Dismiss') }}">
                                    <x-heroicon-m-trash class="h-3.5 w-3.5" />
                                    {{ __('Dismiss') }}
                                </button>
                            @endif
                        </div>

                        {{-- Batch name + meta --}}
                        <p
                            class="pr-36 text-sm font-medium truncate {{ $failing ? 'text-danger-700 dark:text-danger-400' : 'text-gray-700 dark:text-gray-200' }}">
                            {{ $batch['name'] ?: 'Batch #' . substr($batch['id'], 0, 8) }}
                        </p>
                        <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
                            {{ $batch['processed'] }} / {{ $batch['total'] }} {{ __('jobs') }}
                            @if ($batch['failed'] > 0)
                                &nbsp;·&nbsp;<span class="text-danger-600 dark:text-danger-400">{{ $batch['failed'] }}
                                    {{ __('failed') }}</span>
                            @endif
                            @if (!empty($batch['eta_label']))
                                &nbsp;·&nbsp;≈ {{ $batch['eta_label'] }} {{ __('left') }}
                            @endif
                        </p>

                        {{-- Progress bar --}}
                        <div class="mt-2 flex items-center gap-2">
                            <div
                                class="h-1.5 flex-1 overflow-hidden rounded-full {{ $failing ? 'bg-danger-100 dark:bg-danger-900/50' : 'bg-gray-200 dark:bg-white/10' }}">
                                <div class="h-full rounded-full transition-all duration-500 {{ $failing ? 'bg-danger-500' : 'bg-primary-500' }}"
                                    style="width: {{ $batch['progress'] }}%"></div>
                            </div>
                            <span
                                class="w-8 shrink-0 text-right text-xs text-gray-400 dark:text-gray-500">{{ $batch['progress'] }}%</span>
                        </div>
                    </div>
                @endforeach

                {{-- Standalone running jobs (not part of a visible batch) --}}
                @php
                    $batchIds = collect($batches)->pluck('id')->all();
                    $standaloneJobs = collect($running_jobs)->filter(
                        fn($j) => empty($j['batch_id']) || !in_array($j['batch_id'], $batchIds),
                    );
                @endphp

                @if ($standaloneJobs->isNotEmpty())
                    <div class="space-y-2 pt-1">
                        @foreach ($standaloneJobs as $job)
                            @php $chunk = $job['chunk'] ?? null; @endphp
                            <div
                                class="rounded-lg border border-gray-200 bg-gray-50 px-3 pt-2.5 pb-2 dark:border-white/10 dark:bg-white/5">
                                <div class="flex items-center gap-2 text-xs">
                                    <svg class="h-3 w-3 shrink-0 animate-spin text-primary-500"
                                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10"
                                            stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor"
                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                    </svg>
                                    <span
                                        class="font-medium text-gray-700 dark:text-gray-200">{{ $job['human_name'] ?? ($job['name'] ?? __('Unknown job')) }}</span>
                                    @if ($chunk)
                                        <span class="text-gray-500 dark:text-gray-400">{{ $chunk['current'] }} /
                                            {{ $chunk['total'] }}</span>
                                    @endif
                                    <span
                                        class="ml-auto rounded bg-gray-100 px-1.5 py-0.5 text-gray-500 dark:bg-white/10 dark:text-gray-400">{{ $job['queue'] ?? 'default' }}</span>
                                </div>
                                @if ($chunk && $chunk['total'] > 0)
                                    @php $chunkPct = round($chunk['current'] / $chunk['total'] * 100); @endphp
                                    <div class="mt-2 flex items-center gap-2">
                                        <div
                                            class="h-1.5 flex-1 overflow-hidden rounded-full bg-gray-200 dark:bg-white/10">
                                            <div class="h-full rounded-full bg-primary-500 transition-all duration-500"
                                                style="width: {{ $chunkPct }}%"></div>
                                        </div>
                                        <span
                                            class="w-8 shrink-0 text-right text-xs text-gray-400 dark:text-gray-500">{{ $chunkPct }}%</span>
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        </x-filament::section>
    @endif
</x-filament-widgets::widget>
