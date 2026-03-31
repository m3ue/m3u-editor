<?php

/**
 * Wrap hardcoded strings in plugin Blade views with __()
 */
$replacements = [

    // ── plugins-overview-widget.blade.php ──────────────────────────────────
    'resources/views/filament/widgets/plugins-overview-widget.blade.php' => [
        // Manage button
        "                    Manage\n" => "                    {{ __('Manage') }}\n",

        // Stat labels in PHP array
        "['label' => 'Installed', 'value' => \$installed, 'warn' => false]," => "['label' => __('Installed'), 'value' => \$installed, 'warn' => false],",

        "['label' => 'Enabled',   'value' => \$enabled,   'warn' => false]," => "['label' => __('Enabled'), 'value' => \$enabled, 'warn' => false],",

        "['label' => 'Trusted',   'value' => \$trusted,   'warn' => false]," => "['label' => __('Trusted'), 'value' => \$trusted, 'warn' => false],",

        "['label' => 'Pending Trust', 'value' => \$pending, 'warn' => \$pending > 0]," => "['label' => __('Pending Trust'), 'value' => \$pending, 'warn' => \$pending > 0],",

        // Recent Runs label (already has __() but let's check)
        // View button
        "                                        View\n" => "                                        {{ __('View') }}\n",
    ],

    // ── plugins-dashboard.blade.php ────────────────────────────────────────
    'resources/views/filament/pages/plugins-dashboard.blade.php' => [
        // Section: Plugins Needing Attention
        "                        <h2 class=\"text-lg font-semibold text-gray-900 dark:text-white\">Plugins Needing Attention\n                        </h2>" => "                        <h2 class=\"text-lg font-semibold text-gray-900 dark:text-white\">{{ __('Plugins Needing Attention') }}\n                        </h2>",

        "                            These plugins have issues that need your attention — they may be blocked, modified, invalid, or\n                            incomplete." => "                            {{ __('These plugins have issues that need your attention — they may be blocked, modified, invalid, or incomplete.') }}",

        '                        {{ $attentionPlugins->count() }} shown' => "                        {{ \$attentionPlugins->count() }} {{ __('shown') }}",

        '                        No plugins currently need attention.' => "                        {{ __('No plugins currently need attention.') }}",

        "{{ \$plugin->source_type ?: 'unknown source' }}" => "{{ \$plugin->source_type ?: __('unknown source') }}",

        "                                            Trust:\n                                            {{ str(\$plugin->trust_state ?: 'pending_review')->replace('_', ' ')->headline() }}\n                                            · Files:\n                                            {{ str(\$plugin->integrity_status ?: 'unknown')->replace('_', ' ')->headline() }}\n                                            · Status:\n                                            {{ str(\$plugin->installation_status ?: 'installed')->replace('_', ' ')->headline() }}" => "                                            {{ __('Trust') }}:\n                                            {{ str(\$plugin->trust_state ?: 'pending_review')->replace('_', ' ')->headline() }}\n                                            · {{ __('Files') }}:\n                                            {{ str(\$plugin->integrity_status ?: 'unknown')->replace('_', ' ')->headline() }}\n                                            · {{ __('Status') }}:\n                                            {{ str(\$plugin->installation_status ?: 'installed')->replace('_', ' ')->headline() }}",

        // Open buttons (both instances)
        "                                        Open\n                                    </x-filament::button>\n                                </div>\n                            </div>\n                        @endforeach\n                    </div>\n                @endif\n            </x-filament::card>\n        </div>\n\n        <x-filament::card" => "                                        {{ __('Open') }}\n                                    </x-filament::button>\n                                </div>\n                            </div>\n                        @endforeach\n                    </div>\n                @endif\n            </x-filament::card>\n        </div>\n\n        <x-filament::card",

        // Section: Recent Runs
        "            <h2 class=\"text-lg font-semibold text-gray-900 dark:text-white\">Recent Runs</h2>\n            <p class=\"mt-1 text-sm text-gray-500 dark:text-gray-400\">\n                The latest plugin executions across all installed plugins.\n            </p>" => "            <h2 class=\"text-lg font-semibold text-gray-900 dark:text-white\">{{ __('Recent Runs') }}</h2>\n            <p class=\"mt-1 text-sm text-gray-500 dark:text-gray-400\">\n                {{ __('The latest plugin executions across all installed plugins.') }}\n            </p>",

        "                    No plugin runs recorded yet.\n                </div>" => "                    {{ __('No plugin runs recorded yet.') }}\n                </div>",

        "                                        View\n                                    </x-filament::button>" => "                                        {{ __('View') }}\n                                    </x-filament::button>",

        // Section: Recent Plugin Installs
        "                        <h2 class=\"text-lg font-semibold text-gray-900 dark:text-white\">Recent Plugin Installs</h2>\n                        <p class=\"mt-1 text-sm text-gray-500 dark:text-gray-400\">\n                            Recent plugin uploads — pending approval, approved, or rejected.\n                        </p>" => "                        <h2 class=\"text-lg font-semibold text-gray-900 dark:text-white\">{{ __('Recent Plugin Installs') }}</h2>\n                        <p class=\"mt-1 text-sm text-gray-500 dark:text-gray-400\">\n                            {{ __('Recent plugin uploads — pending approval, approved, or rejected.') }}\n                        </p>",

        "                        View Queue\n                    </x-filament::button>" => "                        {{ __('View Queue') }}\n                    </x-filament::button>",

        '                        No plugin installs yet.' => "                        {{ __('No plugin installs yet.') }}",

        "{{ \$review->plugin_name ?: \$review->plugin_id ?: 'Unknown Plugin' }}" => "{{ \$review->plugin_name ?: \$review->plugin_id ?: __('Unknown Plugin') }}",

        "                                            Scan: {{ str(\$review->scan_status ?: 'pending')->replace('_', ' ')->headline() }}" => "                                            {{ __('Scan') }}: {{ str(\$review->scan_status ?: 'pending')->replace('_', ' ')->headline() }}",

        // Second Open button (installs section)
        "                                        Open\n                                    </x-filament::button>\n                                </div>\n                            </div>\n                        @endforeach\n                    </div>\n                @endif\n            </x-filament::card>\n        @endif" => "                                        {{ __('Open') }}\n                                    </x-filament::button>\n                                </div>\n                            </div>\n                        @endforeach\n                    </div>\n                @endif\n            </x-filament::card>\n        @endif",
    ],

    // ── view-plugin-run.blade.php ───────────────────────────────────────────
    'resources/views/filament/resources/extension-plugins/pages/view-plugin-run.blade.php' => [
        // Dry run badge
        "                                Dry run\n" => "                                {{ __('Dry run') }}\n",

        // Plugin run detail label
        '                            Plugin run detail</p>' => "                            {{ __('Plugin run detail') }}</p>",

        // Unknown plugin fallback
        "'Unknown plugin'" => "__('Unknown plugin')",

        // Summary fallback
        "'This run has no summary yet. Use the payload, result, and log stream below to inspect what happened.'" => "__('This run has no summary yet. Use the payload, result, and log stream below to inspect what happened.')",

        // Invocation card
        '                                Invocation</div>' => "                                {{ __('Invocation') }}</div>",

        "'Plugin run'" => "__('Plugin run')",

        // Current signal card
        '                                Current signal</div>' => "                                {{ __('Current signal') }}</div>",

        "'No log messages yet'" => "__('No log messages yet')",

        ">{{ \$progress }}% recorded\n                                progress.</div>" => ">{{ \$progress }}% {{ __('recorded progress.') }}</div>",

        // Queued by card
        '                                Queued by</div>' => "                                {{ __('Queued by') }}</div>",

        "'System'" => "__('System')",

        // Lifecycle card
        '                            Lifecycle</div>' => "                            {{ __('Lifecycle') }}</div>",

        '                            <dt class="text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500">Queued</dt>' => "                            <dt class=\"text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500\">{{ __('Queued') }}</dt>",

        "                            <dt class=\"text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500\">Started\n                                </dt>" => "                            <dt class=\"text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500\">{{ __('Started') }}</dt>",

        "'Not started'" => "__('Not started')",

        "                            <dt class=\"text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500\">Finished\n                                </dt>" => "                            <dt class=\"text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500\">{{ __('Finished') }}</dt>",

        "'Still running'" => "__('Still running')",

        "                            <dt class=\"text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500\">Last\n                                    heartbeat</dt>" => "                            <dt class=\"text-xs uppercase tracking-wide text-gray-400 dark:text-gray-500\">{{ __('Last heartbeat') }}</dt>",

        "'No heartbeat yet'" => "__('No heartbeat yet')",

        // Returned totals card
        '                            Returned totals</div>' => "                            {{ __('Returned totals') }}</div>",

        "                                <span class=\"text-sm text-gray-500 dark:text-gray-400\">This run did not publish aggregate\n                                    totals.</span>" => "                                <span class=\"text-sm text-gray-500 dark:text-gray-400\">{{ __('This run did not publish aggregate totals.') }}</span>",

        // Artifact
        '                                <div class="font-semibold text-gray-700 dark:text-gray-100">Artifact</div>' => "                                <div class=\"font-semibold text-gray-700 dark:text-gray-100\">{{ __('Artifact') }}</div>",

        // Activity stream
        "                        <h2 class=\"text-sm font-semibold text-gray-950 dark:text-white\">Activity stream</h2>\n                        <p class=\"mt-1 text-sm text-gray-500 dark:text-gray-400\">Latest persisted log messages for this\n                            run.</p>" => "                        <h2 class=\"text-sm font-semibold text-gray-950 dark:text-white\">{{ __('Activity stream') }}</h2>\n                        <p class=\"mt-1 text-sm text-gray-500 dark:text-gray-400\">{{ __('Latest persisted log messages for this run.') }}</p>",
    ],
];

$base = __DIR__.'/..';
$totalChanged = 0;

foreach ($replacements as $file => $pairs) {
    $path = $base.'/'.$file;
    if (! file_exists($path)) {
        echo "MISSING: $file\n";

        continue;
    }

    $content = file_get_contents($path);
    $original = $content;
    $changed = 0;

    foreach ($pairs as $search => $replace) {
        if (str_contains($content, $search)) {
            $content = str_replace($search, $replace, $content);
            $changed++;
        } else {
            echo "  NOT FOUND in $file: ".substr(trim($search), 0, 80)."\n";
        }
    }

    if ($content !== $original) {
        file_put_contents($path, $content);
        echo "✓ $file — $changed replacements\n";
        $totalChanged += $changed;
    } else {
        echo "  (no changes) $file\n";
    }
}

echo "\nTotal replacements: $totalChanged\n";
