<div class="space-y-3">
    <div class="text-sm text-gray-500 dark:text-gray-400">
        <div><strong>{{ __('Last failed') }}:</strong> {{ $lastFailedAt }}</div>
        <div><strong>{{ __('Total failures') }}:</strong> {{ $failures }}</div>
    </div>
    <div>
        <div class="mb-1 text-sm font-medium">{{ __('Error message') }}</div>
        <pre class="max-h-96 overflow-y-auto rounded bg-gray-100 p-3 font-mono text-xs break-words whitespace-pre-wrap text-gray-900 dark:bg-gray-800 dark:text-gray-100">{{ $message }}</pre>
    </div>
</div>
