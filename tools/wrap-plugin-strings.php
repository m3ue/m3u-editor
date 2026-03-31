<?php

/**
 * Wraps hardcoded string arguments in PluginResource custom helper methods with __().
 * Handles: infoCard(), pillList(), mutedMessage(), mutedBadge(), statPill(), stackedStat()
 * and inline HTML string literals inside heroPanel() and similar methods.
 */
$files = [
    __DIR__.'/../app/Filament/Resources/Plugins/PluginResource.php',
    __DIR__.'/../app/Filament/Resources/Plugins/Pages/EditPlugin.php',
    __DIR__.'/../app/Filament/Resources/PluginInstallReviews/Pages/EditPluginInstallReview.php',
];

$totalReplacements = 0;

foreach ($files as $file) {
    $content = file_get_contents($file);
    $original = $content;

    // 1. self::infoCard('Title', 'Subtitle', ...) — wrap first and second string args
    //    Pattern: infoCard(\s*'string', \s*'string'
    $content = preg_replace_callback(
        "/self::infoCard\(\s*'([^'\\\\]*)'/",
        fn ($m) => str_contains($m[0], '__') ? $m[0] : "self::infoCard(__('".addcslashes($m[1], '\\')."')",
        $content
    );
    $content = preg_replace_callback(
        "/self::infoCard\(__\('([^']*)'\),\s*'([^'\\\\]*)'/",
        fn ($m) => "self::infoCard(__('".addcslashes($m[1], '\\')."'), __('".addcslashes($m[2], '\\')."')",
        $content
    );

    // 2. self::pillList([...], 'fallback') — wrap last string arg
    $content = preg_replace_callback(
        "/self::pillList\(([^,]+),\s*'([^'\\\\]*)'\)/s",
        function ($m) {
            if (str_contains($m[2], '__')) {
                return $m[0];
            }

            return "self::pillList({$m[1]}, __('".addcslashes($m[2], '\\')."'))";
        },
        $content
    );

    // 3. self::mutedMessage('string') — wrap the arg
    $content = preg_replace_callback(
        "/self::mutedMessage\('([^'\\\\]*)'\)/",
        fn ($m) => str_contains($m[1], '__') ? $m[0] : "self::mutedMessage(__('".addcslashes($m[1], '\\')."'))",
        $content
    );

    // 4. self::mutedBadge('string') — wrap non-dynamic args
    //    Skip ones that use . concatenation (dynamic)
    $content = preg_replace_callback(
        "/self::mutedBadge\('([^'\\\\]*)'\)/",
        fn ($m) => str_contains($m[1], '__') ? $m[0] : "self::mutedBadge(__('".addcslashes($m[1], '\\')."'))",
        $content
    );

    // 5. self::statPill('Label', ..., 'Hint') — wrap first arg only (value is dynamic)
    $content = preg_replace_callback(
        "/self::statPill\('([^'\\\\]*)'/",
        fn ($m) => str_contains($m[0], '__') ? $m[0] : "self::statPill(__('".addcslashes($m[1], '\\')."')",
        $content
    );
    // Also wrap last hint arg: , 'hint string')
    $content = preg_replace_callback(
        "/self::statPill\(__\('([^']*)'\),\s*(.*?),\s*'([^'\\\\]*)'\)/s",
        fn ($m) => "self::statPill(__('".addcslashes($m[1], '\\')."'), {$m[2]}, __('".addcslashes($m[3], '\\')."'))",
        $content
    );

    // 6. self::stackedStat('Label', ...) — wrap first arg only
    $content = preg_replace_callback(
        "/self::stackedStat\('([^'\\\\]*)'/",
        fn ($m) => str_contains($m[0], '__') ? $m[0] : "self::stackedStat(__('".addcslashes($m[1], '\\')."')",
        $content
    );

    // 7. Inline HTML strings for heroPanel and similar methods
    //    These are string literals inside PHP heredoc-like concatenations
    $inlineReplacements = [
        // heroPanel
        "'Plugin control center'" => "__('Plugin control center')",
        "'Trust & Security'" => "__('Trust & Security')",
        "'No description provided.'" => "__('No description provided.')",
        "'Inspect this run'" => "__('Inspect this run')",
        "'Use the header actions to run this plugin once, then track the job from Live Activity and Run History.'" => "__('Use the header actions to run this plugin once, then track the job from Live Activity and Run History.')",
        // runPostureCard
        "'No summary has been written yet.'" => "__('No summary has been written yet.')",
        "'Open run details'" => "__('Open run details')",
        // nextStepCard messages
        "'Validate the plugin before you enable it or queue any work. The system should treat this plugin as untrusted until the contract checks pass.'" => "__('Validate the plugin before you enable it or queue any work. The system should treat this plugin as untrusted until the contract checks pass.')",
        "'An administrator still needs to trust this plugin. Review the declared permissions, owned schema, and file integrity before enabling it.'" => "__('An administrator still needs to trust this plugin. Review the declared permissions, owned schema, and file integrity before enabling it.')",
        "'Integrity is no longer verified. Re-run integrity verification and trust review before allowing this plugin to execute again.'" => "__('Integrity is no longer verified. Re-run integrity verification and trust review before allowing this plugin to execute again.')",
        "'The plugin is valid but disabled. Enable it first, then run a dry scan so you can inspect the output before applying repairs.'" => "__('The plugin is valid but disabled. Enable it first, then run a dry scan so you can inspect the output before applying repairs.')",
        "'Queue a scan from the header to generate the first run. That will populate Live Activity, Run History, and the run detail screen.'" => "__('Queue a scan from the header to generate the first run. That will populate Live Activity, Run History, and the run detail screen.')",
        "'Open the current run and watch the activity stream. If the run stalls, inspect the payload to confirm the target playlist and EPG pair.'" => "__('Open the current run and watch the activity stream. If the run stalls, inspect the payload to confirm the target playlist and EPG pair.')",
        "'Review the failed run, check the activity stream for the error context, and correct the target playlist, EPG, or thresholds before trying again.'" => "__('Review the failed run, check the activity stream for the error context, and correct the target playlist, EPG, or thresholds before trying again.')",
        "'Use the last completed run as your baseline. If the candidate count looks right, queue an apply run or tighten the thresholds from the Settings tab.'" => "__('Use the last completed run as your baseline. If the candidate count looks right, queue an apply run or tighten the thresholds from the Settings tab.')",
        // automationCard inline
        "'Auto scan on EPG cache: enabled'" => "__('Auto scan on EPG cache: enabled')",
        "'Auto scan on EPG cache: disabled'" => "__('Auto scan on EPG cache: disabled')",
        "'Scheduled scans: disabled'" => "__('Scheduled scans: disabled')",
        "'These values prefill manual actions and are reused when hooks or schedules queue work automatically.'" => "__('These values prefill manual actions and are reused when hooks or schedules queue work automatically.')",
        // pluginIdentity
        "'No description provided.'" => "__('No description provided.')",
        "'None declared'" => "__('None declared')",
        "'Lifecycle: disable pauses execution, uninstall changes lifecycle state, forget registry only removes the row.'" => "__('Lifecycle: disable pauses execution, uninstall changes lifecycle state, forget registry only removes the row.')",
        // Yes/No
        "? 'Yes' : 'No'" => "? __('Yes') : __('No')",
        // Version prefix in pluginIdentity
        "'Version '" => "__('Version').__(' ')",
    ];

    foreach ($inlineReplacements as $search => $replace) {
        if (str_contains($content, $search)) {
            $content = str_replace($search, $replace, $content);
        }
    }

    // 8. Dynamic notification bodies with variables — use __() with named placeholders
    // "Plugin install #{$review->id} installed [{$review->plugin_id}]."
    $content = str_replace(
        '->body("Plugin install #{$review->id} installed [{$review->plugin_id}].")',
        '->body(__("Plugin install #:id installed [:plugin_id].", [\'id\' => $review->id, \'plugin_id\' => $review->plugin_id]))',
        $content
    );
    $content = str_replace(
        '->body("Plugin install #{$review->id} installed and trusted [{$review->plugin_id}].")',
        '->body(__("Plugin install #:id installed and trusted [:plugin_id].", [\'id\' => $review->id, \'plugin_id\' => $review->plugin_id]))',
        $content
    );
    $content = str_replace(
        '->body("Plugin install #{$review->id} was rejected.")',
        '->body(__("Plugin install #:id was rejected.", [\'id\' => $review->id]))',
        $content
    );
    $content = str_replace(
        '->body("Review #{$review->id} is queued — check Plugin Installs to scan and approve it.")',
        '->body(__("Review #:id is queued — check Plugin Installs to scan and approve it.", [\'id\' => $review->id]))',
        $content
    );

    if ($content !== $original) {
        file_put_contents($file, $content);
        $count = substr_count($content, '__') - substr_count($original, '__');
        echo 'Updated: '.basename($file)." (+{$count} __() calls)\n";
        $totalReplacements += $count;
    } else {
        echo 'No changes: '.basename($file)."\n";
    }
}

echo "\nTotal new __() calls: {$totalReplacements}\n";
