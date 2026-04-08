<?php

require_once __DIR__.'/../../plugins-bundled/tv-logos/Plugin.php';

use AppLocalPlugins\TvLogos\Plugin;

function resolveLogoUrlForTest(Plugin $plugin, string $channelName, array $index): ?string
{
    $method = new ReflectionMethod(Plugin::class, 'resolveLogoUrl');
    $method->setAccessible(true);

    return $method->invoke($plugin, $channelName, 'de', 'germany', $index);
}

test('it prefers hd folder logos when channel has hd quality hint', function () {
    $plugin = new Plugin;

    $url = resolveLogoUrlForTest($plugin, 'RTL HD', [
        'rtl-de.png' => true,
        'hd/rtl-de.png' => true,
    ]);

    expect($url)->toBe('https://cdn.jsdelivr.net/gh/tv-logo/tv-logos@main/countries/germany/hd/rtl-de.png');
});

test('it falls back to root logos when hd folder has no matching file', function () {
    $plugin = new Plugin;

    $url = resolveLogoUrlForTest($plugin, 'Das Erste HD', [
        'das-erste-de.png' => true,
    ]);

    expect($url)->toBe('https://cdn.jsdelivr.net/gh/tv-logo/tv-logos@main/countries/germany/das-erste-de.png');
});

test('it keeps root priority for non-hd channel names', function () {
    $plugin = new Plugin;

    $url = resolveLogoUrlForTest($plugin, 'ProSieben', [
        'prosieben-de.png' => true,
        'hd/prosieben-de.png' => true,
    ]);

    expect($url)->toBe('https://cdn.jsdelivr.net/gh/tv-logo/tv-logos@main/countries/germany/prosieben-de.png');
});
