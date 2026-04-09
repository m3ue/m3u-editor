<?php

require_once __DIR__.'/../../plugins-bundled/tv-logos/Plugin.php';

use AppLocalPlugins\TvLogos\Plugin;

/**
 * @param  array<string, true>  $index
 */
function resolveLogoUrlForTest(Plugin $plugin, string $channelName, array $index): ?string
{
    // Set the instance properties that processPlaylist normally initializes
    $cdnProp = new ReflectionProperty(Plugin::class, 'cdnBase');
    $cdnProp->setValue($plugin, 'https://cdn.jsdelivr.net/gh/tv-logo/tv-logos@main/countries');

    $apiProp = new ReflectionProperty(Plugin::class, 'indexApiBase');
    $apiProp->setValue($plugin, 'https://api.github.com/repos/tv-logo/tv-logos/contents/countries');

    $method = new ReflectionMethod(Plugin::class, 'resolveLogoUrl');

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
        'pro-sieben-de.png' => true,
        'hd/pro-sieben-de.png' => true,
    ]);

    expect($url)->toBe('https://cdn.jsdelivr.net/gh/tv-logo/tv-logos@main/countries/germany/pro-sieben-de.png');
});

test('it splits camelCase names and matches hd subfolder for HD channels', function () {
    $plugin = new Plugin;

    $url = resolveLogoUrlForTest($plugin, 'ProSieben FUN HD', [
        'hd/pro-sieben-fun-hd-de.png' => true,
        'pro-sieben-fun-de.png' => true,
    ]);

    expect($url)->toBe('https://cdn.jsdelivr.net/gh/tv-logo/tv-logos@main/countries/germany/hd/pro-sieben-fun-hd-de.png');
});

test('it treats dots as word separators', function () {
    $plugin = new Plugin;

    $url = resolveLogoUrlForTest($plugin, 'SAT.1 Gold HD', [
        'sat-1-gold-de.png' => true,
        'hd/sat-1-gold-hd-de.png' => true,
    ]);

    expect($url)->toBe('https://cdn.jsdelivr.net/gh/tv-logo/tv-logos@main/countries/germany/hd/sat-1-gold-hd-de.png');
});

test('it converts plus sign to word plus', function () {
    $plugin = new Plugin;

    $url = resolveLogoUrlForTest($plugin, 'ANIXE+ HD', [
        'anixe-plus-de.png' => true,
        'hd/anixe-plus-hd-de.png' => true,
    ]);

    expect($url)->toBe('https://cdn.jsdelivr.net/gh/tv-logo/tv-logos@main/countries/germany/hd/anixe-plus-hd-de.png');
});

test('compact matching catches hyphenation differences', function () {
    $plugin = new Plugin;

    // Channel slug: "sport1" — index has "sport-1" with a hyphen
    $url = resolveLogoUrlForTest($plugin, 'Sport1', [
        'sport-1-de.png' => true,
    ]);

    expect($url)->toBe('https://cdn.jsdelivr.net/gh/tv-logo/tv-logos@main/countries/germany/sport-1-de.png');
});
