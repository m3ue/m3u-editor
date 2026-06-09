@php
    $isUser = ($msg['role'] ?? '') === 'user';
    $isSystem = ($msg['role'] ?? '') === 'system';
    $isAssistant = ($msg['role'] ?? '') === 'assistant';
    $isTool = ($msg['role'] ?? '') === 'tool';
    $isToolCall = ($msg['role'] ?? '') === 'tool_call';
    $isToolResult = ($msg['role'] ?? '') === 'tool_result';
@endphp

@if ($isUser)
    <div class="flex items-start gap-2.5 justify-end">
        <div class="min-w-0 max-w-[85%] rounded-2xl rounded-tr-md px-3.5 py-2.5 bg-primary-600 text-white">
            <p class="text-sm whitespace-pre-wrap wrap-break-word leading-relaxed">{{ $msg['content'] }}</p>
        </div>
        <div class="w-7 h-7 rounded-full bg-gray-200 dark:bg-gray-700 flex items-center justify-center shrink-0 mt-0.5">
            <x-filament::icon icon="heroicon-o-user" class="w-4 h-4 text-gray-600 dark:text-gray-300" />
        </div>
    </div>
@elseif($isToolCall || $isToolResult || $isTool)
    @php
        $toolName = $msg['tool_name'] ?? ($msg['name'] ?? 'Tool');
        $isSuccess = $msg['success'] ?? true;
        $hasResult = !empty($msg['result']);
        $hasError = !empty($msg['error']);
    @endphp
    <div class="flex items-start gap-2.5">
        <div class="w-7 h-7 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center shrink-0 mt-0.5">
            <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="w-4 h-4 text-gray-500" />
        </div>
        <div class="min-w-0 max-w-[85%] w-full" x-data="{ open: false }">
            <button @click="open = !open" type="button"
                class="flex items-center gap-2 px-3 py-2 w-full rounded-t-xl border transition-colors {{ $hasError ? 'bg-danger-50 dark:bg-danger-900/10 border-danger-200 dark:border-danger-800 hover:bg-danger-100 dark:hover:bg-danger-900/20' : 'bg-success-50 dark:bg-success-900/10 border-success-200 dark:border-success-800 hover:bg-success-100 dark:hover:bg-success-900/20' }}"
                :class="{ 'rounded-b-xl': !open }">
                <svg class="w-3.5 h-3.5 text-gray-500 transition-transform duration-200" :class="{ 'rotate-90': open }"
                    fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
                @if ($hasError)
                    <x-filament::icon icon="heroicon-o-x-circle" class="w-3.5 h-3.5 text-danger-500" />
                @else
                    <x-filament::icon icon="heroicon-o-check-circle" class="w-3.5 h-3.5 text-success-500" />
                @endif
                <span class="text-xs font-medium text-gray-700 dark:text-gray-300 truncate">{{ $toolName }}</span>
            </button>
            <div x-show="open" x-collapse
                class="px-3 py-2 border border-t-0 rounded-b-xl {{ $hasError ? 'bg-danger-50/50 dark:bg-danger-900/5 border-danger-200 dark:border-danger-800' : 'bg-success-50/50 dark:bg-success-900/5 border-success-200 dark:border-success-800' }}">
                @if (!empty($msg['arguments']))
                    <div class="mb-1">
                        <span
                            class="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Arguments</span>
                        <pre
                            class="text-xs text-gray-600 dark:text-gray-400 font-mono whitespace-pre-wrap break-all mt-0.5 max-h-24 overflow-y-auto">{{ is_string($msg['arguments']) ? $msg['arguments'] : json_encode($msg['arguments'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                @endif
                @if ($hasResult || !empty($msg['content']))
                    <div>
                        <span class="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Result</span>
                        <pre class="text-xs text-gray-600 dark:text-gray-400 font-mono whitespace-pre-wrap break-all mt-0.5 max-h-24 overflow-y-auto">{{ $msg['result'] ?? $msg['content'] }}</pre>
                    </div>
                @endif
                @if ($hasError)
                    <div>
                        <span class="text-[10px] font-semibold text-danger-500 uppercase tracking-wider">Error</span>
                        <p class="text-xs text-danger-600 dark:text-danger-400 mt-0.5">{{ $msg['error'] }}</p>
                    </div>
                @endif
            </div>
        </div>
    </div>
@elseif($isAssistant)
    @php
        $assistantHtml = \Illuminate\Support\Str::markdown(
            preg_replace('/<think>.*?<\/think>/s', '', $msg['content'] ?? '')
        );
    @endphp
    <div class="flex items-start gap-2.5">
        <div
            class="w-7 h-7 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center shrink-0 mt-0.5">
            <x-filament::icon icon="heroicon-o-sparkles" class="w-4 h-4 text-primary-600 dark:text-primary-400" />
        </div>
        <div
            class="min-w-0 max-w-[85%] rounded-2xl rounded-tl-md px-3.5 py-2.5 bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-gray-100">
            <div
                class="text-sm leading-relaxed prose prose-sm dark:prose-invert max-w-none wrap-break-word [&>*:first-child]:mt-0 [&>*:last-child]:mb-0">
                {!! $assistantHtml !!}
            </div>
        </div>
    </div>
@elseif($isSystem)
    <div class="flex justify-center px-4">
        <div
            class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-xl bg-warning-50 dark:bg-warning-900/20 text-warning-700 dark:text-warning-300 max-w-full">
            <x-filament::icon icon="heroicon-o-exclamation-triangle" class="w-3.5 h-3.5 shrink-0" />
            <span class="text-xs wrap-anywherebreak-word">{{ $msg['content'] }}</span>
        </div>
    </div>
@endif
