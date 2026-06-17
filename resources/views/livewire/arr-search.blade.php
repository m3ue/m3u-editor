<div wire:poll.{{ $this->queuePollInterval }}s="loadQueue">
    @if(! $guestMode)
        {{-- Admin: integration selector --}}
        <div class="mb-4 flex flex-col sm:flex-row sm:items-center gap-3">
            <label for="arr-integration-select" class="text-sm font-medium text-gray-700 dark:text-gray-300 whitespace-nowrap">
                {{ __('Integration:') }}
            </label>
            <select
                id="arr-integration-select"
                wire:model.live="integrationId"
                class="flex-1 rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 focus:border-primary-500 focus:ring-primary-500"
            >
                <option value="">{{ __('Select a Sonarr/Radarr integration...') }}</option>
                @foreach(\App\Models\ArrIntegration::query()->where('user_id', auth()->id())->where('enabled', true)->orderBy('name')->get() as $integrationOption)
                    <option value="{{ $integrationOption->id }}">
                        {{ $integrationOption->name }} ({{ ucfirst($integrationOption->type) }})
                    </option>
                @endforeach
            </select>
            @if($queue)
                <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-medium bg-primary-100 text-primary-800 dark:bg-primary-900/30 dark:text-primary-300">
                    <x-heroicon-o-arrow-down-tray class="w-3 h-3 mr-1" />
                    {{ __('Queue: :count active', ['count' => count($queue)]) }}
                </span>
            @endif
        </div>
    @endif

    @if($this->integration)
        {{-- Search input --}}
        <div class="mb-6">
            <form wire:submit.prevent="search" class="relative">
                <input
                    type="text"
                    wire:model.live.debounce.300ms="searchTerm"
                    placeholder="{{ $this->integration->isSonarr() ? __('Search TV series...') : __('Search movies...') }}"
                    class="w-full pl-10 pr-4 py-3 rounded-lg border border-gray-300 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100 focus:border-primary-500 focus:ring-primary-500"
                >
                <x-heroicon-o-magnifying-glass class="absolute left-3 top-1/2 -translate-y-1/2 w-5 h-5 text-gray-400" />
                <div wire:loading wire:target="search" class="absolute right-3 top-1/2 -translate-y-1/2">
                    <x-filament::loading-indicator class="h-5 w-5 text-primary-500" />
                </div>
            </form>
        </div>

        {{-- Results grid --}}
        @if($isSearching)
            <div class="flex items-center justify-center py-12">
                <x-filament::loading-indicator class="h-8 w-8 text-primary-500" />
                <span class="ml-3 text-gray-600 dark:text-gray-400">{{ __('Searching...') }}</span>
            </div>
        @elseif(count($results) > 0)
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                @foreach($results as $index => $result)
                    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-sm border border-gray-200 dark:border-gray-700 overflow-hidden flex flex-col">
                        @if(! empty($result['poster']))
                            <img
                                src="{{ $result['poster'] }}"
                                alt="{{ $result['title'] ?? '' }}"
                                class="w-full aspect-[2/3] object-cover"
                                loading="lazy"
                            >
                        @else
                            <div class="w-full aspect-[2/3] bg-gray-200 dark:bg-gray-700 flex items-center justify-center">
                                @if($this->integration->isSonarr())
                                    <x-heroicon-o-tv class="w-12 h-12 text-gray-400 dark:text-gray-500" />
                                @else
                                    <x-heroicon-o-film class="w-12 h-12 text-gray-400 dark:text-gray-500" />
                                @endif
                            </div>
                        @endif

                        <div class="p-3 flex-1 flex flex-col">
                            <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 line-clamp-2">
                                {{ $result['title'] ?? __('Unknown') }}
                                @if(! empty($result['year']))
                                    <span class="text-gray-500 dark:text-gray-400 font-normal">({{ $result['year'] }})</span>
                                @endif
                            </h3>

                            @if(! empty($result['overview']))
                                <p class="mt-1 text-xs text-gray-600 dark:text-gray-400 line-clamp-3 flex-1">
                                    {{ $result['overview'] }}
                                </p>
                            @endif

                            <button
                                wire:click="request({{ $index }})"
                                wire:loading.attr="disabled"
                                class="mt-3 w-full inline-flex items-center justify-center px-3 py-2 text-xs font-medium text-white bg-primary-600 hover:bg-primary-700 disabled:opacity-50 rounded-md focus:outline-none focus:ring-2 focus:ring-primary-500"
                            >
                                <x-heroicon-o-plus class="w-3 h-3 mr-1" />
                                {{ __('Request') }}
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @elseif(strlen(trim($searchTerm)) >= 2)
            <div class="flex flex-col items-center justify-center py-12 text-center">
                <x-heroicon-o-magnifying-glass class="w-12 h-12 text-gray-300 dark:text-gray-600" />
                <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">{{ __('No results found') }}</h4>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Try a different search term.') }}
                </p>
            </div>
        @else
            <div class="flex flex-col items-center justify-center py-12 text-center">
                @if($this->integration->isSonarr())
                    <x-heroicon-o-tv class="w-12 h-12 text-gray-300 dark:text-gray-600" />
                @else
                    <x-heroicon-o-film class="w-12 h-12 text-gray-300 dark:text-gray-600" />
                @endif
                <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                    {{ $this->integration->isSonarr() ? __('Search TV series') : __('Search movies') }}
                </h4>
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Enter a title to search :server.', ['server' => $this->integration->name]) }}
                </p>
            </div>
        @endif

        {{-- Queue panel --}}
        @if($queue)
            <div class="mt-8">
                <h3 class="text-sm font-semibold text-gray-900 dark:text-gray-100 mb-3 flex items-center">
                    <x-heroicon-o-arrow-down-tray class="w-4 h-4 mr-2" />
                    {{ __('Download Queue') }}
                </h3>
                <div class="space-y-2">
                    @foreach($queue as $item)
                        <div class="bg-white dark:bg-gray-800 rounded-md border border-gray-200 dark:border-gray-700 p-3">
                            <div class="flex items-center justify-between">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-gray-900 dark:text-gray-100 truncate">
                                        {{ $item['title'] ?? __('Unknown') }}
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        {{ $item['status'] ?? '' }}
                                        @if($item['timeLeft'] ?? null)
                                            · {{ __(':time left', ['time' => $item['timeLeft']]) }}
                                        @endif
                                    </p>
                                </div>
                                <span class="text-xs font-medium text-primary-600 dark:text-primary-400 ml-3">
                                    {{ $item['progress'] ?? 0 }}%
                                </span>
                            </div>
                            <div class="mt-2 w-full bg-gray-200 dark:bg-gray-700 rounded-full h-1.5 overflow-hidden">
                                <div
                                    class="bg-primary-600 h-1.5 rounded-full transition-all duration-300"
                                    style="width: {{ $item['progress'] ?? 0 }}%"
                                ></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @else
        <div class="flex flex-col items-center justify-center py-12 text-center">
            <x-heroicon-o-magnifying-glass-circle class="w-12 h-12 text-gray-300 dark:text-gray-600" />
            <h4 class="mt-2 text-sm font-medium text-gray-900 dark:text-gray-100">
                {{ $guestMode ? __('No integrations available') : __('Select an integration to begin') }}
            </h4>
            @unless($guestMode)
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    {{ __('Choose a Sonarr or Radarr server from the dropdown above.') }}
                </p>
            @endunless
        </div>
    @endif
</div>
