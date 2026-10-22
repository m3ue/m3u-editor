<div class="space-y-4">
    <div class="">
        @if ($asset->isInlinePreviewable())
            <img
                src="{{ $asset->preview_url }}"
                alt="{{ $asset->name }}"
                class="max-h-80 w-auto rounded-lg border border-gray-200 bg-white object-contain dark:border-gray-700 dark:bg-gray-900"
            />
        @else
            <div class="rounded-lg border border-dashed border-gray-300 p-6 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
                {{ __('assets.no_inline_preview') }}
                <x-filament::button tag="a" :href="$asset->preview_url" icon="heroicon-o-arrow-down-tray" class="mt-3">
                    {{ __('assets.download') }}
                </x-filament::button>
            </div>
        @endif
    </div>

    <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
        <div>
            <dt class="font-medium text-gray-500 dark:text-gray-400">Source</dt>
            <dd class="text-gray-900 dark:text-gray-100">{{ $asset->source }}</dd>
        </div>
        <div>
            <dt class="font-medium text-gray-500 dark:text-gray-400">Disk</dt>
            <dd class="text-gray-900 dark:text-gray-100">{{ $asset->disk }}</dd>
        </div>
        <div class="sm:col-span-2">
            <dt class="font-medium text-gray-500 dark:text-gray-400">Path</dt>
            <dd class="break-all text-gray-900 dark:text-gray-100">{{ $asset->path }}</dd>
        </div>
        <div>
            <dt class="font-medium text-gray-500 dark:text-gray-400">MIME Type</dt>
            <dd class="text-gray-900 dark:text-gray-100">{{ $asset->mime_type ?? '-' }}</dd>
        </div>
        <div>
            <dt class="font-medium text-gray-500 dark:text-gray-400">Size</dt>
            <dd class="text-gray-900 dark:text-gray-100">
                {{ $asset->size_bytes ? number_format($asset->size_bytes / 1024, 2) . ' KB' : '-' }}
            </dd>
        </div>
        <div>
            <dt class="font-medium text-gray-500 dark:text-gray-400">Modified</dt>
            <dd class="text-gray-900 dark:text-gray-100">{{ $asset->last_modified_at?->toDateTimeString() ?? '-' }}</dd>
        </div>
    </dl>

    <div>
        <h4 class="font-medium text-gray-900 dark:text-gray-100">Metadata</h4>
        @if (! empty($metadata))
            <pre class="mt-2 max-h-80 overflow-auto rounded-lg border border-gray-200 bg-gray-50 p-3 text-xs text-gray-800 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">{{ json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        @else
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">No metadata found for this asset.</p>
        @endif
    </div>
</div>
