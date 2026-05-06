@php
    /** @var \App\Models\SyncRun $record */
    $errors = $record->errors ?? [];
@endphp

@if(empty($errors))
    <p class="text-sm text-gray-500 dark:text-gray-400">{{ __('No errors recorded.') }}</p>
@else
    <ul class="space-y-2">
        @foreach($errors as $error)
            <li class="rounded-md border border-red-200 dark:border-red-900/50 bg-red-50 dark:bg-red-900/20 px-3 py-2">
                <div class="flex flex-wrap items-center gap-2 text-xs text-red-700 dark:text-red-300">
                    @if(! empty($error['phase']))
                        <span class="font-mono px-1.5 py-0.5 rounded bg-red-100 dark:bg-red-900/40">
                            {{ $error['phase'] }}
                        </span>
                    @endif
                    @if(! empty($error['exception']))
                        <span class="font-mono">{{ $error['exception'] }}</span>
                    @endif
                    @if(! empty($error['at']))
                        <span class="text-red-500/80 dark:text-red-400/70">{{ $error['at'] }}</span>
                    @endif
                </div>
                <div class="mt-1 text-sm text-red-800 dark:text-red-200">
                    {{ $error['message'] ?? '' }}
                </div>
            </li>
        @endforeach
    </ul>
@endif
