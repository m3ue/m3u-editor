<?php

use App\Jobs\MapPlaylistChannelsToEpgChunk;

// Expose the protected extractCallsign method via an anonymous subclass.
function makeTestJob(): MapPlaylistChannelsToEpgChunk
{
    return new class([], 0, 0, [], 'test', 0) extends MapPlaylistChannelsToEpgChunk
    {
        public function exposeExtractCallsign(string $name): ?string
        {
            return $this->extractCallsign($name);
        }
    };
}

// --- US callsigns (FCC: K / W prefix) ---

it('extracts a 4-letter K-prefix callsign', function () {
    $job = makeTestJob();

    expect($job->exposeExtractCallsign('US: CBS 13 (KOVR) STOCKTON HD'))->toBe('KOVR');
    expect($job->exposeExtractCallsign('US: FOX (KMOV) ST LOUIS HD'))->toBe('KMOV');
});

it('extracts a 4-letter W-prefix callsign', function () {
    $job = makeTestJob();

    expect($job->exposeExtractCallsign('US: CBS 6 (WKMG) ORLANDO HD'))->toBe('WKMG');
    expect($job->exposeExtractCallsign('US: CBS 2 (WBBM) CHICAGO HD'))->toBe('WBBM');
});

it('extracts a 3-letter callsign (legacy stations)', function () {
    $job = makeTestJob();

    expect($job->exposeExtractCallsign('WGN (WGN) CHICAGO'))->toBe('WGN');
    expect($job->exposeExtractCallsign('KGO (KGO) SAN FRANCISCO'))->toBe('KGO');
});

it('extracts a callsign with a -DT digital suffix', function () {
    $job = makeTestJob();

    expect($job->exposeExtractCallsign('US: CBS 6 (WKMG-DT) ORLANDO HD'))->toBe('WKMG-DT');
    expect($job->exposeExtractCallsign('KDKA (KDKA-DT) PITTSBURGH'))->toBe('KDKA-DT');
});

it('extracts a callsign with a subchannel digit', function () {
    $job = makeTestJob();

    expect($job->exposeExtractCallsign('US: FOX (WLOX-DT2) BILOXI HD'))->toBe('WLOX-DT2');
});

it('extracts a callsign with other digital suffixes', function () {
    $job = makeTestJob();

    expect($job->exposeExtractCallsign('Channel (KLPA-LD)'))->toBe('KLPA-LD');
    expect($job->exposeExtractCallsign('Channel (KABC-CD)'))->toBe('KABC-CD');
});

// --- Canadian callsigns (CRTC: C prefix) ---

it('extracts a Canadian C-prefix callsign', function () {
    $job = makeTestJob();

    expect($job->exposeExtractCallsign('CTV (CFTO) TORONTO'))->toBe('CFTO');
    expect($job->exposeExtractCallsign('Global (CJOH) OTTAWA'))->toBe('CJOH');
});

// --- Case insensitivity ---

it('is case-insensitive and always returns uppercase', function () {
    $job = makeTestJob();

    expect($job->exposeExtractCallsign('Some Channel (kovr) HD'))->toBe('KOVR');
    expect($job->exposeExtractCallsign('Some Channel (Wbbm-dt)'))->toBe('WBBM-DT');
});

// --- Non-callsign tokens that must NOT match ---

it('returns null for quality / format tokens', function () {
    $job = makeTestJob();

    expect($job->exposeExtractCallsign('Channel (HD)'))->toBeNull();   // 2 chars
    expect($job->exposeExtractCallsign('Channel (UHD)'))->toBeNull();  // U prefix
    expect($job->exposeExtractCallsign('Channel (FHD)'))->toBeNull();  // F prefix
    expect($job->exposeExtractCallsign('Channel (HEVC)'))->toBeNull(); // H prefix
    expect($job->exposeExtractCallsign('Channel (SDT)'))->toBeNull();  // S prefix
});

it('returns null for feed / tier labels', function () {
    $job = makeTestJob();

    expect($job->exposeExtractCallsign('BT Sport (HOME)'))->toBeNull();   // H prefix
    expect($job->exposeExtractCallsign('BT Sport (AWAY)'))->toBeNull();   // A prefix
    expect($job->exposeExtractCallsign('Sky Sports (VIP)'))->toBeNull();  // V prefix
    expect($job->exposeExtractCallsign('Sky Sports (BACKUP)'))->toBeNull(); // B prefix, >4 letters
});

it('returns null for ISO country / language codes', function () {
    $job = makeTestJob();

    expect($job->exposeExtractCallsign('BT Sport 1 (ENG) HD'))->toBeNull(); // E prefix
    expect($job->exposeExtractCallsign('Eurosport (FRA)'))->toBeNull();      // F prefix
    expect($job->exposeExtractCallsign('Channel (GBR)'))->toBeNull();        // G prefix
});

it('returns null for sport league descriptors', function () {
    $job = makeTestJob();

    expect($job->exposeExtractCallsign('Game (NHL)'))->toBeNull();    // N prefix
    expect($job->exposeExtractCallsign('Game (MLS)'))->toBeNull();    // M prefix
    expect($job->exposeExtractCallsign('Game (LALIGA)'))->toBeNull(); // L prefix, >4 letters
});

it('returns null when no parenthetical token is present', function () {
    $job = makeTestJob();

    expect($job->exposeExtractCallsign('CNN HD'))->toBeNull();
    expect($job->exposeExtractCallsign('US: ESPN 2'))->toBeNull();
    expect($job->exposeExtractCallsign(''))->toBeNull();
});
