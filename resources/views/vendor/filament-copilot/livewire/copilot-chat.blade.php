<div x-data="{
    open: @entangle('isOpen'),
    sidebarOpen: false,
    sidebarLoading: false,
    conversationLoading: false,
    streamingEnabled: true,
    isStreaming: false,
    streamedContent: '',
    pendingComplete: false,
    toolCalls: [],
    _cleanups: [],
    _abortController: null,
    _pendingNavigateUrl: null,
    init() {
        const wireComponentId = $wire.__instance.id;

        this.$watch('open', value => {
            if (value) {
                this.$nextTick(() => {
                    this.scrollToBottom();
                    this.focusInput();
                });
            } else {
                this.sidebarOpen = false;
            }
        });

        // SPA-safe: store cleanup functions for all global hooks
        this._cleanups.push(
            Livewire.hook('morph.updated', ({ el, component }) => {
                // Only react to morphs from our own Livewire component
                if (component.id !== wireComponentId) return;
                if (el.id === 'copilot-messages') {
                    if (this.pendingComplete) {
                        // Immediately hide the overlay via style before Alpine reactivity
                        // to guarantee zero-flash when server message is already in DOM
                        const overlay = this.$el.querySelector('[data-streaming-overlay]');
                        if (overlay) overlay.style.display = 'none';

                                        this.pendingComplete = false;
                        this.streamedContent = '';
                        this.toolCalls = [];
                        this.isStreaming = false;
                    }
                    this.conversationLoading = false;
                    this.$nextTick(() => this.scrollToBottom());
                }
            })
        );

        this._cleanups.push(
            Livewire.on('copilot-send-stream', (data) => {
                this.startStreaming(data[0] || data);
            })
        );

        this._cleanups.push(
            Livewire.on('copilot-load-conversation', () => {
                this.conversationLoading = true;
                this.sidebarOpen = false;
            })
        );
    },
    destroy() {
        // SPA-safe: deregister all global Livewire hooks/listeners
        this._cleanups.forEach(fn => fn());
        this._cleanups = [];

        // Abort any in-flight SSE stream
        if (this._abortController) {
            this._abortController.abort();
            this._abortController = null;
        }
    },
    scrollToBottom() {
        const container = this.$refs.messages;
        if (container) {
            container.scrollTop = container.scrollHeight;
        }
    },
    focusInput() {
        const ta = this.$el.querySelector('textarea');
        if (ta) ta.focus();
    },
    handleKeydown(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            if (!this.isStreaming && !$wire.isLoading) {
                $wire.sendMessage();
            }
        }
    },
    autoResize(el) {
        el.style.height = 'auto';
        el.style.height = Math.min(el.scrollHeight, 120) + 'px';
    },
    toggleSidebar() {
        this.sidebarOpen = !this.sidebarOpen;
        if (this.sidebarOpen) {
            this.sidebarLoading = true;
            $wire.dispatch('copilot-refresh-sidebar');
            setTimeout(() => { this.sidebarLoading = false; }, 600);
        }
    },
    async startStreaming(params) {
        // Abort any previous in-flight stream
        if (this._abortController) {
            this._abortController.abort();
        }
        this._abortController = new AbortController();

        this.isStreaming = true;
        this.streamedContent = '';
        this.toolCalls = [];
        this.pendingComplete = false;

        try {
            const response = await fetch(params.streamUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'text/event-stream',
                    'X-CSRF-TOKEN': params.csrfToken,
                },
                body: JSON.stringify({
                    message: params.message,
                    conversation_id: params.conversationId,
                    panel_id: params.panelId,
                }),
                signal: this._abortController.signal,
            });

            if (!response.ok) {
                throw new Error('Stream request failed: ' + response.status);
            }

            const reader = response.body.getReader();
            const decoder = new TextDecoder();
            let buffer = '';
            let newConversationId = null;

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop() || '';

                let currentEvent = null;

                for (const line of lines) {
                    if (line.startsWith('event: ')) {
                        currentEvent = line.substring(7).trim();
                    } else if (line.startsWith('data: ') && currentEvent) {
                        try {
                            const data = JSON.parse(line.substring(6));

                            switch (currentEvent) {
                                case 'conversation':
                                    newConversationId = data.id;
                                    break;
                                case 'chunk':
                                    this.streamedContent += data.text;
                                    this.$nextTick(() => this.scrollToBottom());
                                    break;
                                case 'tool_call':
                                    this.toolCalls.push({
                                        id: data.tool_id,
                                        name: data.tool_name,
                                        arguments: data.arguments,
                                        status: 'running',
                                        result: null,
                                    });
                                    this.$nextTick(() => this.scrollToBottom());
                                    break;
                                case 'tool_result': {
                                    const idx = this.toolCalls.findIndex(t =>
                                        (data.tool_id && t.id === data.tool_id) ||
                                        (!data.tool_id && t.name === data.tool_name)
                                    );
                                    if (idx !== -1) {
                                        this.toolCalls[idx].status = data.success ? 'done' : 'error';
                                        this.toolCalls[idx].result = data.result;
                                        this.toolCalls[idx].error = data.error;
                                    }
                                }
                                this.$nextTick(() => this.scrollToBottom());
                                break;
                                case 'navigate':
                                    // Queue navigation URL to execute after stream completes
                                    this._pendingNavigateUrl = data.url;
                                    break;
                                case 'error':
                                    $wire.dispatchSelf('copilot-stream-error', { error: data.message });
                                    this.isStreaming = false;
                                    this.streamedContent = '';
                                    this.toolCalls = [];
                                    break;
                                case 'done':
                                    break;
                            }
                        } catch (e) {
                            // Skip malformed JSON
                        }

                        currentEvent = null;
                    }
                }
            }

            this._abortController = null;

            if (this.streamedContent) {
                this.pendingComplete = true;
                this.isStreaming = false;

                // Keep streamedContent in memory — overlay stays invisible until the Livewire morph replaces it.
                $wire.dispatchSelf('copilot-stream-complete', {
                    content: this.streamedContent,
                    newConversationId: newConversationId,
                    toolCalls: this.toolCalls.length ? JSON.parse(JSON.stringify(this.toolCalls)) : null,
                });

                // Execute pending navigation after stream completes
                if (this._pendingNavigateUrl) {
                    const url = this._pendingNavigateUrl;
                    this._pendingNavigateUrl = null;
                    // Small delay to let the completion dispatch process first
                    setTimeout(() => {
                        Livewire.navigate(url);
                    }, 300);
                }
            } else {
                // Execute pending navigation even if no streamed content
                if (this._pendingNavigateUrl) {
                    const url = this._pendingNavigateUrl;
                    this._pendingNavigateUrl = null;
                    setTimeout(() => {
                        Livewire.navigate(url);
                    }, 300);
                }
                this.isStreaming = false;
                this.toolCalls = [];
            }
        } catch (error) {
            this._abortController = null;

            // Silently handle intentional aborts (navigation, new stream, destroy)
            if (error.name === 'AbortError') {
                this.isStreaming = false;
                this.streamedContent = '';
                this.toolCalls = [];
                return;
            }

            $wire.dispatchSelf('copilot-stream-error', { error: error.message });
            this.isStreaming = false;
            this.streamedContent = '';
            this.toolCalls = [];
        }
    }
}" x-cloak @copilot-open.window="open = true" @copilot-close-sidebar.window="sidebarOpen = false"
    @copilot-load-conversation.window="sidebarOpen = false" @keydown.escape.window="if(open) open = false">

    {{-- Backdrop overlay --}}
    <div x-show="open" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100" x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" @click="open = false"
        class="fixed inset-0 z-40 bg-gray-950/50 dark:bg-gray-950/75"></div>

    {{-- Slide-over drawer --}}
    <div x-show="open" x-transition:enter="transition ease-out duration-300 transform"
        x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
        x-transition:leave="transition ease-in duration-200 transform" x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        class="copilot-chat-widget fixed inset-y-0 right-0 z-50 flex flex-col h-dvh w-screen max-w-md bg-white dark:bg-gray-900 shadow-xl ring-1 ring-gray-950/5 dark:ring-white/10 overflow-hidden">

        {{-- Header (sticky, matches Filament slide-over) --}}
        <div
            class="sticky top-0 z-10 flex items-center justify-between px-6 py-4 border-b border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 shrink-0">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-primary-50 dark:bg-primary-500/10 flex items-center justify-center">
                    <x-filament::icon icon="heroicon-o-sparkles"
                        class="w-5 h-5 text-primary-600 dark:text-primary-400" />
                </div>
                <h2 class="text-base font-semibold leading-6 text-gray-950 dark:text-white">
                    {{ __('filament-copilot::filament-copilot.title') }}</h2>
            </div>
            <div class="flex items-center gap-3">
                <button x-show="$wire.conversationId" wire:click="exportConversation" wire:loading.attr="disabled"
                    type="button"
                    class="fi-icon-btn relative flex items-center justify-center rounded-lg outline-none transition duration-75 focus-visible:ring-2 hover:bg-gray-500/5 dark:hover:bg-gray-400/5 fi-color-gray w-8 h-8"
                    title="{{ __('filament-copilot::filament-copilot.export') }}">
                    <x-filament::icon icon="heroicon-o-arrow-down-tray" class="w-5 h-5 text-gray-400 dark:text-gray-500"
                        wire:loading.class="animate-pulse" wire:target="exportConversation" />
                </button>
                <button @click="toggleSidebar()" type="button"
                    class="fi-icon-btn relative flex items-center justify-center rounded-lg outline-none transition duration-75 focus-visible:ring-2 w-8 h-8"
                    :class="sidebarOpen ? 'bg-primary-50 dark:bg-primary-500/10 text-primary-600 dark:text-primary-400' :
                        'hover:bg-gray-500/5 dark:hover:bg-gray-400/5 text-gray-400 dark:text-gray-500'"
                    title="{{ __('filament-copilot::filament-copilot.history') }}">
                    <x-filament::icon icon="heroicon-o-clock" class="w-5 h-5" />
                </button>
                <button wire:click="newConversation" type="button"
                    class="fi-icon-btn relative flex items-center justify-center rounded-lg outline-none transition duration-75 focus-visible:ring-2 hover:bg-gray-500/5 dark:hover:bg-gray-400/5 fi-color-gray w-8 h-8"
                    title="{{ __('filament-copilot::filament-copilot.new_conversation') }}">
                    <x-filament::icon icon="heroicon-o-plus" class="w-5 h-5 text-gray-400 dark:text-gray-500" />
                </button>
                <button @click="open = false" type="button"
                    class="fi-icon-btn fi-modal-close-btn relative flex items-center justify-center rounded-lg outline-none transition duration-75 focus-visible:ring-2 hover:bg-gray-500/5 dark:hover:bg-gray-400/5 fi-color-gray w-8 h-8"
                    title="{{ __('filament-copilot::filament-copilot.close') ?? 'Close' }}">
                    <x-filament::icon icon="heroicon-o-x-mark" class="w-5 h-5 text-gray-400 dark:text-gray-500" />
                </button>
            </div>
        </div>

        {{-- Content area with sidebar overlay --}}
        <div class="flex-1 flex relative overflow-hidden min-h-0">

            {{-- Conversation Sidebar --}}
            <div x-show="sidebarOpen" x-transition:enter="transition ease-out duration-150"
                x-transition:enter-start="opacity-0 -translate-x-4" x-transition:enter-end="opacity-100 translate-x-0"
                x-transition:leave="transition ease-in duration-100"
                x-transition:leave-start="opacity-100 translate-x-0" x-transition:leave-end="opacity-0 -translate-x-4"
                x-cloak class="absolute inset-0 z-10 bg-white dark:bg-gray-900 flex flex-col">

                {{-- Sidebar loading skeleton --}}
                <div x-show="sidebarLoading" class="flex flex-col h-full">
                    <div
                        class="flex items-center justify-between px-4 py-3 border-b border-gray-200 dark:border-gray-700">
                        <div class="h-4 w-32 bg-gray-200 dark:bg-gray-700 rounded animate-pulse"></div>
                        <div class="flex gap-1">
                            <div class="h-7 w-7 bg-gray-200 dark:bg-gray-700 rounded-lg animate-pulse"></div>
                            <div class="h-7 w-7 bg-gray-200 dark:bg-gray-700 rounded-lg animate-pulse"></div>
                        </div>
                    </div>
                    <div class="flex-1 p-2 space-y-2">
                        <template x-for="i in 5" :key="i">
                            <div class="px-3 py-3 rounded-lg">
                                <div class="h-4 bg-gray-200 dark:bg-gray-700 rounded animate-pulse mb-2"
                                    :style="'width: ' + (60 + Math.random() * 30) + '%'"></div>
                                <div class="h-3 w-24 bg-gray-100 dark:bg-gray-800 rounded animate-pulse"></div>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Actual sidebar content --}}
                <div x-show="!sidebarLoading" class="flex flex-col h-full">
                    @livewire('filament-copilot-sidebar', ['activeConversationId' => $conversationId])
                </div>
            </div>

            {{-- Conversation loading overlay --}}
            <div x-show="conversationLoading" x-transition.opacity.duration.200ms x-cloak
                class="absolute inset-0 z-20 bg-white/80 dark:bg-gray-900/80 backdrop-blur-sm flex items-center justify-center">
                <div class="flex flex-col items-center gap-3">
                    <svg class="animate-spin w-7 h-7 text-primary-500" xmlns="http://www.w3.org/2000/svg" fill="none"
                        viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                            stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                    <span
                        class="text-xs text-gray-500 dark:text-gray-400">{{ __('filament-copilot::filament-copilot.loading') }}...</span>
                </div>
            </div>

            {{-- Messages --}}
            <div id="copilot-messages" x-ref="messages" class="flex-1 overflow-y-auto px-4 py-4 space-y-4">
                @if (empty($messages))
                    <div
                        class="flex flex-col items-center justify-center h-full text-center text-gray-400 dark:text-gray-500 gap-4 px-4">
                        <div
                            class="w-14 h-14 rounded-2xl bg-primary-50 dark:bg-primary-900/20 flex items-center justify-center">
                            <x-filament::icon icon="heroicon-o-sparkles"
                                class="w-8 h-8 text-primary-400 dark:text-primary-500" />
                        </div>
                        <div>
                            <p class="text-sm font-medium text-gray-600 dark:text-gray-300">
                                {{ __('filament-copilot::filament-copilot.welcome_message') }}</p>
                            <p class="text-xs text-gray-400 dark:text-gray-500 mt-1">
                                {{ __('filament-copilot::filament-copilot.input_placeholder') }}</p>
                        </div>

                        {{-- Quick Actions --}}
                        @if (!empty($quickActions))
                            <div class="flex flex-wrap justify-center gap-2 mt-1 max-w-sm">
                                @foreach ($quickActions as $action)
                                    <button
                                        wire:click="$dispatch('copilot-quick-action', { prompt: '{{ addslashes($action['prompt'] ?? $action) }}' })"
                                        type="button"
                                        class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-medium rounded-xl border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-800 hover:border-gray-300 dark:hover:border-gray-600 transition-colors">
                                        @if (isset($action['icon']))
                                            <x-filament::icon :icon="$action['icon']" class="w-3.5 h-3.5" />
                                        @endif
                                        {{ $action['label'] ?? $action }}
                                    </button>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @else
                    @foreach ($messages as $msg)
                        @include('filament-copilot::components.chat-message', ['msg' => $msg])
                    @endforeach
                @endif

                {{-- Live Tool Call Boxes --}}
                <template x-for="(tool, toolIdx) in toolCalls" :key="toolIdx">
                    <div class="flex items-start gap-2.5" x-show="isStreaming || pendingComplete">
                        <div
                            class="w-7 h-7 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center shrink-0 mt-0.5">
                            <x-filament::icon icon="heroicon-o-wrench-screwdriver" class="w-4 h-4 text-gray-500" />
                        </div>
                        <div class="min-w-0 max-w-[85%] w-full" x-data="{ toolOpen: false }">
                            <button @click="toolOpen = !toolOpen" type="button"
                                class="flex items-center gap-2 px-3 py-2 w-full rounded-t-xl border transition-colors"
                                :class="{
                                    'rounded-b-xl': !toolOpen,
                                    'bg-gray-50 dark:bg-gray-800/50 border-gray-200 dark:border-gray-700 hover:bg-gray-100 dark:hover:bg-gray-800': tool
                                        .status === 'running',
                                    'bg-success-50 dark:bg-success-900/10 border-success-200 dark:border-success-800 hover:bg-success-100 dark:hover:bg-success-900/20': tool
                                        .status === 'done',
                                    'bg-danger-50 dark:bg-danger-900/10 border-danger-200 dark:border-danger-800 hover:bg-danger-100 dark:hover:bg-danger-900/20': tool
                                        .status === 'error',
                                }">
                                <svg class="w-3.5 h-3.5 text-gray-500 transition-transform duration-200"
                                    :class="{ 'rotate-90': toolOpen }" fill="none" viewBox="0 0 24 24"
                                    stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M9 5l7 7-7 7" />
                                </svg>
                                <template x-if="tool.status === 'running'">
                                    <svg class="animate-spin w-3.5 h-3.5 text-gray-400"
                                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10"
                                            stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor"
                                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                                        </path>
                                    </svg>
                                </template>
                                <template x-if="tool.status === 'done'">
                                    <x-filament::icon icon="heroicon-o-check-circle"
                                        class="w-3.5 h-3.5 text-success-500" />
                                </template>
                                <template x-if="tool.status === 'error'">
                                    <x-filament::icon icon="heroicon-o-x-circle"
                                        class="w-3.5 h-3.5 text-danger-500" />
                                </template>
                                <span class="text-xs font-medium text-gray-700 dark:text-gray-300 truncate"
                                    x-text="tool.name"></span>
                            </button>
                            <div x-show="toolOpen" x-collapse class="px-3 py-2 border border-t-0 rounded-b-xl"
                                :class="{
                                    'bg-gray-50/50 dark:bg-gray-800/30 border-gray-200 dark:border-gray-700': tool
                                        .status === 'running',
                                    'bg-success-50/50 dark:bg-success-900/5 border-success-200 dark:border-success-800': tool
                                        .status === 'done',
                                    'bg-danger-50/50 dark:bg-danger-900/5 border-danger-200 dark:border-danger-800': tool
                                        .status === 'error',
                                }">
                                <template x-if="tool.arguments">
                                    <div class="mb-1">
                                        <span
                                            class="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Arguments</span>
                                        <pre class="text-xs text-gray-600 dark:text-gray-400 font-mono whitespace-pre-wrap break-all mt-0.5 max-h-24 overflow-y-auto"
                                            x-text="JSON.stringify(tool.arguments, null, 2)"></pre>
                                    </div>
                                </template>
                                <template x-if="tool.result">
                                    <div>
                                        <span
                                            class="text-[10px] font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Result</span>
                                        <pre class="text-xs text-gray-600 dark:text-gray-400 font-mono whitespace-pre-wrap break-all mt-0.5 max-h-24 overflow-y-auto"
                                            x-text="tool.result"></pre>
                                    </div>
                                </template>
                                <template x-if="tool.error">
                                    <div>
                                        <span
                                            class="text-[10px] font-semibold text-danger-500 uppercase tracking-wider">Error</span>
                                        <p class="text-xs text-danger-600 dark:text-danger-400 mt-0.5"
                                            x-text="tool.error">
                                        </p>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>
                </template>

                {{-- Loading indicator during streaming (dots) --}}
                <template x-if="isStreaming">
                    <div class="flex items-start gap-2.5">
                        <div
                            class="w-7 h-7 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center shrink-0 mt-0.5">
                            <x-filament::icon icon="heroicon-o-sparkles"
                                class="w-4 h-4 text-primary-600 dark:text-primary-400" />
                        </div>
                        <div
                            class="flex items-center gap-1.5 py-2.5 px-3.5 bg-gray-100 dark:bg-gray-800 rounded-2xl rounded-tl-md">
                            <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce"
                                style="animation-delay: 0ms"></span>
                            <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce"
                                style="animation-delay: 150ms"></span>
                            <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce"
                                style="animation-delay: 300ms"></span>
                        </div>
                    </div>
                </template>

                {{-- SSE Streaming content (invisible during stream, visible after stream completes while waiting for Livewire morph) --}}
                <div x-show="pendingComplete && streamedContent" x-cloak wire:ignore.self
                    data-streaming-overlay class="flex items-start gap-2.5">
                    <div
                        class="w-7 h-7 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center shrink-0 mt-0.5">
                        <x-filament::icon icon="heroicon-o-sparkles"
                            class="w-4 h-4 text-primary-600 dark:text-primary-400" />
                    </div>
                    <div
                        class="min-w-0 max-w-[85%] rounded-2xl rounded-tl-md px-3.5 py-2.5 bg-gray-100 dark:bg-gray-800 text-gray-900 dark:text-gray-100">
                        <div
                            class="text-sm leading-relaxed prose prose-sm dark:prose-invert max-w-none wrap-break-word">
                            <p x-text="streamedContent" class="whitespace-pre-wrap"></p>
                        </div>
                    </div>
                </div>

                {{-- Loading indicator (synchronous fallback) --}}
                <div wire:loading wire:target="sendMessage" x-show="!isStreaming" class="flex items-start gap-2.5">
                    <div
                        class="w-7 h-7 rounded-full bg-primary-100 dark:bg-primary-900/30 flex items-center justify-center shrink-0 mt-0.5">
                        <x-filament::icon icon="heroicon-o-sparkles"
                            class="w-4 h-4 text-primary-600 dark:text-primary-400" />
                    </div>
                    <div
                        class="flex items-center gap-1.5 py-2.5 px-3.5 bg-gray-100 dark:bg-gray-800 rounded-2xl rounded-tl-md">
                        <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce"
                            style="animation-delay: 0ms"></span>
                        <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce"
                            style="animation-delay: 150ms"></span>
                        <span class="w-1.5 h-1.5 bg-gray-400 rounded-full animate-bounce"
                            style="animation-delay: 300ms"></span>
                    </div>
                </div>
            </div>
        </div>

        {{-- Input (sticky footer, matches Filament slide-over) --}}
        <div
            class="sticky bottom-0 border-t border-gray-200 dark:border-white/10 bg-white dark:bg-gray-900 shrink-0 px-6 py-4">
            <form wire:submit="sendMessage"
                class="flex items-end gap-2 rounded-xl ring-1 ring-gray-300 dark:ring-gray-600 bg-white dark:bg-gray-800 p-2 focus-within:ring-2 focus-within:ring-primary-500 transition-shadow">
                <textarea wire:model="message" @keydown="handleKeydown($event)" @input="autoResize($event.target)" rows="1"
                    class="copilot-textarea flex-1 resize-none bg-transparent text-sm text-gray-900 dark:text-white py-1 px-2 border-none shadow-none outline-none placeholder-gray-400 dark:placeholder-gray-500"
                    style="max-height: 120px" placeholder="{{ __('filament-copilot::filament-copilot.input_placeholder') }}"
                    :disabled="$wire.isLoading || isStreaming"></textarea>
                <button type="submit"
                    class="inline-flex items-center justify-center w-8 h-8 rounded-lg bg-primary-600 hover:bg-primary-500 active:bg-primary-700 text-white transition-colors disabled:opacity-40 disabled:cursor-not-allowed shrink-0"
                    :disabled="$wire.isLoading || isStreaming">
                    <svg x-show="!isStreaming && !$wire.isLoading" class="w-4 h-4" viewBox="0 0 24 24"
                        fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M7 11L12 6L17 11M12 18V7" stroke="currentColor" stroke-width="2"
                            stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    <svg x-show="isStreaming || $wire.isLoading" x-cloak class="animate-spin w-4 h-4"
                        xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                            stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor"
                            d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                        </path>
                    </svg>
                </button>
            </form>

            {{-- Keyboard shortcut hint (desktop only) --}}
            <div class="hidden sm:flex items-center justify-center mt-2">
                <span class="text-[10px] text-gray-400 dark:text-gray-500">
                    <kbd
                        class="px-1 py-0.5 rounded border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 font-mono text-[10px]">Enter</kbd>
                    send &middot;
                    <kbd
                        class="px-1 py-0.5 rounded border border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-800 font-mono text-[10px]">Shift+Enter</kbd>
                    new line
                </span>
            </div>
        </div>
    </div>
</div>
