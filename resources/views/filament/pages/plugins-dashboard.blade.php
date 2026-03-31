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
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Plugins Needing Attention') }}
                        </h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('These plugins have issues that need your attention — they may be blocked, modified, invalid, or incomplete.') }}
                        </p>
                    </div>
                    <x-filament::badge color="warning" size="sm">
                        {{ $attentionPlugins->count() }} {{ __('shown') }}
                    </x-filament::badge>
                </div>

                @if ($attentionPlugins->isEmpty())
                    <div
                        class="mt-4 rounded-xl border border-dashed border-green-300 bg-green-50 p-4 text-sm text-green-700 dark:border-green-900 dark:bg-green-950/40 dark:text-green-300">
                        {{ __('No plugins currently need attention.') }}
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
                                            {{ $plugin->plugin_id }} · {{ $plugin->source_type ?: __('unknown source') }}
                                        </p>
                                        <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                                            {{ __('Trust') }}:
                                            {{ str($plugin->trust_state ?: 'pending_review')->replace('_', ' ')->headline() }}
                                            · {{ __('Files') }}:
                                            {{ str($plugin->integrity_status ?: 'unknown')->replace('_', ' ')->headline() }}
                                            · {{ __('Status') }}:
                                            {{ str($plugin->installation_status ?: 'installed')->replace('_', ' ')->headline() }}
                                        </p>
                                    </div>

                                    <x-filament::button tag="a"
                                        href="{{ \App\Filament\Resources\Plugins\PluginResource::getUrl('edit', ['record' => $plugin]) }}"
                                        color="gray" size="sm">
                                        {{ __('Open') }}
                                    </x-filament::button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-filament::card>
        </div>

        <x-filament::card class="p-6">
            <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Recent Runs') }}</h2>
            <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                {{ __('The latest plugin executions across all installed plugins.') }}
            </p>

            @if ($recentRuns->isEmpty())
                <div class="mt-4 rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
                    {{ __('No plugin runs recorded yet.') }}
                </div>
            @else
                <div class="mt-4 divide-y divide-gray-100 dark:divide-white/5">
                    @foreach ($recentRuns as $run)
                        @php
        $runUrl = $run->plugin
            ? \App\Filament\Resources\Plugins\PluginResource::getUrl('run', ['record' => $run->plugin, 'run' => $run])
            : null;
                        @endphp
                        <div class="flex items-center justify-between gap-4 py-3">
                            <div class="flex min-w-0 flex-col gap-0.5">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium text-gray-900 dark:text-white">
                                        {{ $run->plugin?->name ?? '—' }}
                                    </span>
                                    <span class="text-xs text-gray-400 dark:text-gray-500">
                                        {{ \Illuminate\Support\Str::headline($run->action ?? $run->hook ?? $run->trigger ?? '') }}
                                    </span>
                                </div>
                                <span class="text-xs text-gray-400 dark:text-gray-500">
                                    {{ $run->created_at->diffForHumans() }}
                                    @if ($run->finished_at)
                                        · {{ $run->created_at->diffInSeconds($run->finished_at) }}s
                                    @endif
                                </span>
                            </div>

                            <div class="flex shrink-0 items-center gap-3">
                                <x-filament::badge :color="match ($run->status) {
                'completed' => 'success',
                'running' => 'info',
                'failed' => 'danger',
                'cancelled' => 'warning',
                default => 'gray',
            }">
                                    {{ \Illuminate\Support\Str::headline($run->status) }}
                                </x-filament::badge>

                                @if ($runUrl)
                                    <x-filament::button tag="a" href="{{ $runUrl }}" color="gray" size="sm">
                                        {{ __('View') }}
                                    </x-filament::button>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-filament::card>

        @if ($canManagePlugins)
            <x-filament::card class="p-6">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">{{ __('Recent Plugin Installs') }}</h2>
                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                            {{ __('Recent plugin uploads — pending approval, approved, or rejected.') }}
                        </p>
                    </div>
                    <x-filament::button tag="a" href="{{ $pluginInstallsUrl }}" color="gray" size="sm"
                        icon="heroicon-o-arrow-right">
                        {{ __('View Queue') }}
                    </x-filament::button>
                </div>

                @if ($recentInstallReviews->isEmpty())
                    <div
                        class="mt-4 rounded-xl border border-dashed border-gray-300 p-4 text-sm text-gray-600 dark:border-gray-700 dark:text-gray-300">
                        {{ __('No plugin installs yet.') }}
                    </div>
                @else
                    <div class="mt-4 space-y-3">
                        @foreach ($recentInstallReviews as $review)
                            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-800">
                                <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between">
                                    <div class="min-w-0">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <p class="font-medium text-gray-900 dark:text-white">
                                                {{ $review->plugin_name ?: $review->plugin_id ?: __('Unknown Plugin') }}
                                            </p>
                                            <x-filament::badge :color="$this->installStatusColor($review->status)" size="sm">
                                                {{ str($review->status)->replace('_', ' ')->headline() }}
                                            </x-filament::badge>
                                            <x-filament::badge color="gray" size="sm">
                                                {{ str($review->source_type ?: 'unknown')->replace('_', ' ')->headline() }}
                                            </x-filament::badge>
                                        </div>
                                        <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                                            {{ __('Scan') }}: {{ str($review->scan_status ?: 'pending')->replace('_', ' ')->headline() }}
                                            · {{ optional($review->created_at)->diffForHumans() }}
                                        </p>
                                    </div>

                                    <x-filament::button tag="a"
                                        href="{{ \App\Filament\Resources\PluginInstallReviews\PluginInstallReviewResource::getUrl('edit', ['record' => $review]) }}"
                                        color="gray" size="sm">
                                        {{ __('Open') }}
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