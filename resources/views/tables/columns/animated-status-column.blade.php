<div class="flex flex-col gap-y-1">
    @php
        $record = $getRecord();
        $status = $record->status;
        $postProcessingStep = $record->post_processing_step;
        $isRecording = $status === \App\Enums\DvrRecordingStatus::Recording;
        $isPostProcessing = $status === \App\Enums\DvrRecordingStatus::PostProcessing;
        $label = $status?->getLabel() ?? ucfirst($status?->value ?? 'unknown');
    @endphp

    @if ($isRecording)
        <div class="flex items-center gap-2">
            <span class="relative flex h-3 w-3 shrink-0">
                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-500 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-3 w-3 bg-red-600"></span>
            </span>
            <span class="text-xs font-medium text-red-600 dark:text-red-400">{{ $label }}</span>
        </div>
    @elseif ($isPostProcessing)
        <div class="flex items-center gap-2">
            <svg class="animate-spin h-3.5 w-3.5 shrink-0 text-indigo-500 dark:text-indigo-400"
                xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4">
                </circle>
                <path class="opacity-75" fill="currentColor"
                    d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                </path>
            </svg>
            <span class="text-xs font-medium text-indigo-600 dark:text-indigo-400">{{ $label }}</span>
        </div>
    @else
        <x-filament::badge size="sm" :color="$status?->getColor() ?? 'gray'">
            {{ $label }}
        </x-filament::badge>
    @endif

    @if ($postProcessingStep)
        <span class="text-xs text-indigo-600 dark:text-indigo-400 leading-tight">{{ $postProcessingStep }}</span>
    @endif
</div>
