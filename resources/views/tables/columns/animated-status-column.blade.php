<div class="flex flex-col gap-y-1">
    @php
        $record = $getRecord();
        $status = $record->status;
        $postProcessingStep = $record->post_processing_step;

        $colorClasses = match ($status?->getColor()) {
            'info' => 'bg-blue-50 text-blue-700 ring-blue-700/10 dark:bg-blue-400/10 dark:text-blue-200 dark:ring-blue-400/20',
            'warning' => 'bg-yellow-50 text-yellow-700 ring-yellow-600/20 dark:bg-yellow-400/10 dark:text-yellow-200 dark:ring-yellow-400/30',
            'success' => 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-400/10 dark:text-green-200 dark:ring-green-400/30',
            'danger' => 'bg-red-50 text-red-700 ring-red-600/10 dark:bg-red-400/10 dark:text-red-200 dark:ring-red-400/20',
            default => 'bg-gray-50 text-gray-600 ring-gray-600/10 dark:bg-gray-400/10 dark:text-gray-200 dark:ring-gray-400/20',
        };

        $isAnimated = in_array($status, [\App\Enums\DvrRecordingStatus::Recording, \App\Enums\DvrRecordingStatus::PostProcessing], true);
        $animationClass = $isAnimated ? 'dfi-status-animated-pulse' : '';
        $label = $status?->getLabel() ?? ucfirst($status?->value ?? 'unknown');
    @endphp

    <span class="fi-badge fi-color {{ $colorClasses }} {{ $animationClass }}">
        <span class="fi-badge-label">{{ $label }}</span>
    </span>

    @if($postProcessingStep)
        <div class="flex items-center gap-1.5 mt-0.5">
            <svg class="animate-spin h-3.5 w-3.5 text-indigo-500 dark:text-indigo-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            <span class="fi-ta-text-description dfi-description-animated text-xs text-indigo-600 dark:text-indigo-400">{{ $postProcessingStep }}</span>
        </div>
    @endif
</div>
