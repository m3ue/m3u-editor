<x-filament-panels::page>
    <div class="space-y-6">
        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 xl:grid-cols-4">
            @foreach ($summaryCards as $card)
                <x-filament::card class="p-4">
                    <div class="flex items-start gap-4">
                        <div class="rounded-lg p-3
                                    @if ($card['color'] === 'green') bg-green-100 dark:bg-green-900
                                    @elseif ($card['color'] === 'amber') bg-amber-100 dark:bg-amber-900
                                    @elseif ($card['color'] === 'red') bg-red-100 dark:bg-red-900
                                    @else bg-blue-100 dark:bg-blue-900
                                    @endif">
                            <x-dynamic-component :component="$card['icon']" class="h-6 w-6 text-gray-900 dark:text-white" />
                        </div>
                        <div class="min-w-0 flex items-center gap-1">
                            <p class="text-sm font-medium text-gray-600 dark:text-gray-400">{{ $card['label'] }}</p>
                            <p class="text-3xl font-semibold text-gray-900 dark:text-white">{{ $card['value'] }}</p>
                        </div>
                    </div>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">{{ $card['description'] }}</p>
                </x-filament::card>
            @endforeach
        </div>

        <div class="grid grid-cols-1 gap-4">
            <x-filament::card class="p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Plugins Needing Attention
                        </h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            These extensions need review because of trust, integrity, validation, availability, or
                            install state.
                        </p>
                    </div>
                    <x-filament::badge color="warning" size="sm">
                        {{ $attentionPlugins->count() }} shown
                    </x-filament::badge>
                </div>

                @if ($attentionPlugins->isEmpty())
                    <div
                        class="mt-4 rounded-xl border border-dashed border-green-300 bg-green-50 p-4 text-sm text-green-700 dark:border-green-900 dark:bg-green-950/40 dark:text-green-300">
                        No extensions currently need operator attention.
                    </div>
                @else
                    <div class="mt-4 space-y-3">
                        @foreach ($attentionPlugins as $plugin)
                            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="font-medium text-gray-900 dark:text-white">
                                                {{ $plugin->name ?: $plugin->plugin_id }}
                                            </p>
                                            <x-filament::badge :color="$this->pluginHealthColor($plugin)" size="sm">
                                                {{ $this->pluginHealthLabel($plugin) }}
                                            </x-filament::badge>
                                        </div>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                            {{ $plugin->plugin_id }} · {{ $plugin->source_type ?: 'unknown source' }}
                                        </p>
                                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                                            Trust:
                                            {{ str($plugin->trust_state ?: 'pending_review')->replace('_', ' ')->headline() }}
                                            · Integrity:
                                            {{ str($plugin->integrity_status ?: 'unknown')->replace('_', ' ')->headline() }}
                                            · Install:
                                            {{ str($plugin->installation_status ?: 'installed')->replace('_', ' ')->headline() }}
                                        </p>
                                    </div>

                                    <x-filament::button tag="a"
                                        href="{{ \App\Filament\Resources\Plugins\PluginResource::getUrl('edit', ['record' => $plugin]) }}"
                                        color="gray" size="sm">
                                        Open
                                    </x-filament::button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::card>
        </div>

        @if ($canManagePlugins)
            <x-filament::card class="p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">Recent Plugin Installs</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            The latest staged, approved, rejected, or installed extension review records.
                        </p>
                    </div>
                    <x-filament::button tag="a" href="{{ $pluginInstallsUrl }}" color="gray" size="sm"
                        icon="heroicon-o-arrow-right">
                        View Queue
                    </x-filament::button>
                </div>

                @if ($recentInstallReviews->isEmpty())
                    <div
                        class="mt-4 rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
                        No plugin installs have been staged yet.
                    </div>
                @else
                    <div class="mt-4 space-y-3">
                        @foreach ($recentInstallReviews as $review)
                            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="font-medium text-gray-900 dark:text-white">
                                                {{ $review->plugin_name ?: $review->plugin_id ?: 'Unknown Plugin' }}
                                            </p>
                                            <x-filament::badge :color="$this->installStatusColor($review->status)" size="sm">
                                                {{ str($review->status)->replace('_', ' ')->headline() }}
                                            </x-filament::badge>
                                            <x-filament::badge color="gray" size="sm">
                                                {{ str($review->source_type ?: 'unknown')->replace('_', ' ')->headline() }}
                                            </x-filament::badge>
                                        </div>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                            Scan: {{ str($review->scan_status ?: 'pending')->replace('_', ' ')->headline() }}
                                            · {{ optional($review->created_at)->diffForHumans() }}
                                        </p>
                                    </div>

                                    <x-filament::button tag="a"
                                        href="{{ \App\Filament\Resources\PluginInstallReviews\PluginInstallReviewResource::getUrl('edit', ['record' => $review]) }}"
                                        color="gray" size="sm">
                                        Open
                                    </x-filament::button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::card>
        @endif
    </div>
</x-filament-panels::page>