<?php

use App\Services\ChannelTitleSimilarityService;

beforeEach(function () {
    $this->service = new ChannelTitleSimilarityService;
});

it('strips source prefixes and quality decorations down to the channel name', function (string $title, string $expected) {
    expect($this->service->coreName($title))->toBe($expected);
})->with([
    ['DE| SYFY HEVC', 'syfy'],
    ['JOYN| SYFY FHD ᴿᴬᵂ', 'syfy'],
    ['VIP: SKY SPORTS F1 ᴴᴰ ʰᵉᵛᶜ', 'skysportsf1'],
    ['UK: BBC 3 / CBBC HD ◉', 'bbc3cbbc'],
    ['FR - W9 HEVC', 'w9'],
    ['DE: DISCOVERY HD (720P)', 'discovery'],
    ['IT| CIELO UHD', 'cielo'],
]);

it('returns null when a title has no usable content', function (?string $title) {
    expect($this->service->coreName($title))->toBeNull();
})->with([[null], [''], ['   '], ['|'], ['---']]);

it('falls back to the raw text when a title is nothing but noise tokens', function (string $title, string $expected) {
    // Returning null here would make the caller abstain on a title it could
    // still have compared against another.
    expect($this->service->coreName($title))->toBe($expected);
})->with([
    ['HD', 'hd'],
    ['4K', '4k'],
    ['UK| HD', 'ukhd'],
]);

it('keeps tokens that are part of a real channel name', function (string $title, string $expected) {
    // 'ts', 'vip' and 'live' are deliberately absent from the noise list. With
    // them in, "TS TV" and "VIP TV" both reduced to "tv" and matched each other.
    expect($this->service->coreName($title))->toBe($expected);
})->with([
    ['UK| TS TV', 'tstv'],
    ['ES| VIP TV', 'viptv'],
    ['FR: CANAL+ LIVE 14', 'canallive14'],
]);

it('no longer collapses two different channels to an identical core', function () {
    // With 'ts' and 'vip' treated as noise these both reduced to "tv" and
    // matched by exact equality, which no threshold could override. They score
    // 0.44 against each other now, so the threshold decides - which is the
    // point.
    expect($this->service->coreName('UK| TS TV'))->not->toBe($this->service->coreName('ES| VIP TV'));
});

it('refuses two films that share a name but not a year', function () {
    // Normalisation drops the year, so this has to be settled before it runs or
    // both titles reduce to "thething" and match exactly.
    expect($this->service->titlesMatch('EN - The Thing (1982)', 'EN - The Thing (2011)', 0.4))->toBeFalse();
});

it('keeps a region in brackets instead of deleting it', function () {
    // Stripping all bracketed text reduced both of these to "bbcone" and made
    // them an exact match at every threshold. They now differ, and how the pair
    // is treated is left to the threshold - at 0.4 they still score ~0.6, which
    // is the documented limit for same-brand regional feeds.
    expect($this->service->coreName('UK: BBC One (Scotland)'))->toBe('bbconescotland');
    expect($this->service->coreName('UK: BBC One (London)'))->toBe('bbconelondon');
    expect($this->service->titlesMatch('UK: BBC One (Scotland)', 'UK: BBC One (London)', 0.8))->toBeFalse();
});

it('still strips brackets holding nothing but a format marker', function () {
    expect($this->service->coreName('DE: DISCOVERY HD (720P)'))->toBe('discovery');
});

it('does not mistake a multi-word prefix for source metadata', function () {
    // An earlier pattern matched any 12 characters before the delimiter and
    // reduced both of these to "world".
    expect($this->service->coreName('BBC NEWS: WORLD'))->toBe('bbcnewsworld');
    expect($this->service->coreName('CNN NEWS: WORLD'))->toBe('cnnnewsworld');
    // Distinct cores rather than the exact match the old pattern produced.
    // They still share 9 of 12 characters and score ~0.83, so no threshold in
    // normal use separates them - a limitation, but a far cry from collapsing
    // both to "world".
    expect($this->service->coreName('BBC NEWS: WORLD'))
        ->not->toBe($this->service->coreName('CNN NEWS: WORLD'));
});

it('matches an abbreviation on whole words, not stray characters', function () {
    // ["e"] is a leading word of ["e", "entertainment"], but not of ["espn"].
    expect($this->service->coreTokens('FR| E! FHD'))->toBe(['e']);
    expect($this->service->coreTokens('FR - E! ENTERTAINMENT FHD'))->toBe(['e', 'entertainment']);
    expect($this->service->coreTokens('UK| ESPN HD'))->toBe(['espn']);
});

it('does not treat a sibling channel suffix as event detail', function () {
    // "NEWS" is too short to be a fixture, so the event-feed shortcut must not
    // fire for it the way it does for "OH Leuven - Standard".
    $method = new ReflectionMethod($this->service, 'eventFeedMatches');

    expect($method->invoke($this->service, 'UK| SKY SPORTS - NEWS', 'UK| SKY SPORTS'))->toBeFalse();
    expect($method->invoke($this->service, 'BE| DAZN 7 - OH Leuven - Standard', 'BE - DAZN 7'))->toBeTrue();
});

it('does not let truncation manufacture a perfect score', function () {
    // Two names differing only past the scoring cap must not come back
    // identical; the tail is scored as well as the head.
    $prefix = str_repeat('a', 128);

    expect($this->service->similarity($prefix.'x', $prefix.'y'))->toBeLessThan(1.0);
});

it('scores the same regardless of argument order', function () {
    // similar_text() is not commutative, so the raw call can return different
    // figures for (a,b) and (b,a) and flip the verdict.
    $a = 'UK| BUENA VISTA SOCIAL CLUB';
    $b = 'ES| SUPERACION LA HISTORIA';

    expect($this->service->titlesMatch($a, $b, 0.4))
        ->toBe($this->service->titlesMatch($b, $a, 0.4));
});

it('treats a zero threshold as the guard being switched off', function () {
    expect($this->service->titlesMatch('UK: BBC 3', 'UK: FOOD NETWORK', 0.0))->toBeTrue();
});

it('rejects titles that describe genuinely different channels', function (string $master, string $candidate) {
    expect($this->service->titlesMatch($master, $candidate, 0.4))->toBeFalse();
})->with([
    // The case this guard exists for: a mis-parsed stream id grouped these.
    ['UK: BBC 3 / CBBC HD ◉', 'UK: FOOD NETWORK +1 ◉'],
    ['UK: BBC 3 / CBBC HD ◉', 'UK: ITV LONDON 4K ◉'],
    ['FR| TLC HD', 'FR - DISCOVERY SCIENCE'],
    ['ES| 13 TV SD', 'ES| CALLE 13 HEVC'],
    ['UK| TRUE CRIME', 'UK - CBS DRAMA'],
    // Two unrelated films sharing only a language prefix.
    ['EN - Pitch Perfect 3  (2017)', 'ES - Tenéis que venir a verla (2023)'],
    ['EN - Detective Chinatown  (2015)', '4K-EN - Thirteen Lives  (2022)'],
]);

it('accepts the same channel across sources and quality variants', function (string $master, string $candidate) {
    expect($this->service->titlesMatch($master, $candidate, 0.4))->toBeTrue();
})->with([
    ['DE| SYFY HEVC', 'DE| SYFY FHD'],
    ['FR| W9 4K', 'FR - W9 HEVC'],
    ['FR| LCI HD ᴿᴬᵂ', 'FR| LCI FHD'],
    ['IT| CIELO UHD', 'IT: CIELO HEVC'],
    ['VIP: SKY SPORTS F1 ᴴᴰ', 'SKYGO: SKY SPORT F1 4K'],
    ['FR| NAT GEO WILD HD', 'BE| NAT GEOGRAPHIC WILD HD'],
    ['UK| NICKELODEON FHD', 'NOW: NICKELODEON ᴴᴰ'],
]);

it('accepts an event feed matched against its base channel', function () {
    // Comparing the stripped form of both titles would reduce each to its
    // language prefix; the base has to be matched against the whole name.
    expect($this->service->titlesMatch('BE| DAZN 7 - OH Leuven - Standard', 'BE - DAZN 7', 0.4))->toBeTrue();
    expect($this->service->titlesMatch('BE: DAZN 11', 'BE| DAZN 11 - Union SG - RSC Anderlecht', 0.4))->toBeTrue();
});

it('does not let a shared language prefix carry a match on its own', function () {
    // Both titles reduce to "en" before the separator. If the base were
    // compared against the other base rather than the other full name, these
    // two unrelated films would be declared the same channel.
    expect($this->service->titlesMatch('EN - Nine Days  (2021)', 'EN - Hungry Dog Blues  (2022)', 0.4))->toBeFalse();
});

it('accepts an abbreviation contained in its expanded form', function () {
    expect($this->service->titlesMatch('FR| E! FHD', 'FR - E! ENTERTAINMENT FHD', 0.4))->toBeTrue();
    expect($this->service->titlesMatch('UK| NICK JR FHD', 'UK| NICKELODEON JUNIOR HD', 0.4))->toBeTrue();
});

it('abstains only when both names are too short to compare', function () {
    // Both short and unrelated - no signal either way, so keep the pair.
    expect($this->service->titlesMatch('FR| OCS HD', 'RO - TNT', 0.4))->toBeTrue();

    // One short, one long, nothing in common - that is real evidence.
    expect($this->service->titlesMatch('UK: BBC 3 / CBBC HD ◉', 'UK: RT UK HD ◉', 0.4))->toBeFalse();
});

it('scores identical names as a perfect match', function () {
    expect($this->service->similarity('syfy', 'syfy'))->toBe(1.0);
});
