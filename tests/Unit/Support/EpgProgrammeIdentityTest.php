<?php

use App\Support\EpgProgrammeIdentity;

it('derives exact Gracenote identities from trusted programme IDs', function () {
    expect(EpgProgrammeIdentity::fromProgramme([
        'id' => 'occurrence-row',
        'title' => 'Synthetic Series',
        'episode_nums' => [['system' => 'dd_progid', 'value' => 'EP012345670089']],
    ]))->toBe([
        'content_id' => 'gracenote:EP012345670089',
        'series_id' => 'gracenote:SH012345670000',
    ])
        ->and(EpgProgrammeIdentity::fromProgramme([
            'episode_nums' => [['system' => 'dd_progid', 'value' => 'MV000158920000']],
        ]))->toBe(['content_id' => 'gracenote:MV000158920000'])
        ->and(EpgProgrammeIdentity::fromProgramme([
            'episode_nums' => [['system' => 'dd_progid', 'value' => 'SH012599310000']],
        ]))->toBe(['series_id' => 'gracenote:SH012599310000']);
});

it('accepts explicit identities and omits conflicting or malformed values', function () {
    expect(EpgProgrammeIdentity::fromProgramme([
        'episode_nums' => [
            ['system' => 'm3u-editor:content-id', 'value' => 'provider:episode-17'],
            ['system' => 'm3u-editor:series-id', 'value' => 'provider:series-3'],
        ],
    ]))->toBe([
        'content_id' => 'provider:episode-17',
        'series_id' => 'provider:series-3',
    ])
        ->and(EpgProgrammeIdentity::fromProgramme([
            'episode_nums' => [
                ['system' => 'm3u-editor:content-id', 'value' => 'provider:episode-1'],
                ['system' => 'm3u-editor:content-id', 'value' => 'provider:episode-2'],
                ['system' => 'm3u-editor:series-id', 'value' => "bad\nvalue"],
            ],
        ]))->toBe([]);
});
it('rejects contradictions between explicit and provider identities', function () {
    expect(EpgProgrammeIdentity::fromProgramme([
        'episode_nums' => [
            ['system' => 'm3u-editor:content-id', 'value' => 'provider:episode-17'],
            ['system' => 'm3u-editor:series-id', 'value' => 'provider:series-3'],
            ['system' => 'dd_progid', 'value' => 'EP012345670089'],
        ],
    ]))->toBe([])
        ->and(EpgProgrammeIdentity::fromProgramme([
            'episode_nums' => [
                ['system' => 'dd_progid', 'value' => 'EP012345670089'],
                ['system' => 'dd_progid', 'value' => 'EP012345670090'],
            ],
        ]))->toBe(['series_id' => 'gracenote:SH012345670000']);
});

it('never promotes occurrence fields or numbering alone to identity', function () {
    expect(EpgProgrammeIdentity::fromProgramme([
        'id' => 'shared-row-id',
        'title' => 'Same Title',
        'production_year' => 2024,
        'episode_nums' => [['system' => 'xmltv_ns', 'value' => '0.0.']],
    ]))->toBe([]);
});

it('requires matching Schedules Direct ID and entity contracts', function () {
    expect(EpgProgrammeIdentity::fromSchedulesDirect((object) [
        'programID' => 'EP012599310061',
        'entityType' => 'Episode',
    ]))->toBe([
        'content_id' => 'gracenote:EP012599310061',
        'series_id' => 'gracenote:SH012599310000',
    ])
        ->and(EpgProgrammeIdentity::fromSchedulesDirect((object) [
            'programID' => 'MV000158920000',
            'entityType' => 'Movie',
        ]))->toBe(['content_id' => 'gracenote:MV000158920000'])
        ->and(EpgProgrammeIdentity::fromSchedulesDirect((object) [
            'programID' => 'EP012599310061',
            'entityType' => 'Movie',
        ]))->toBe([])
        ->and(EpgProgrammeIdentity::fromSchedulesDirect((object) [
            'programID' => 'EP123',
            'entityType' => 'Episode',
        ]))->toBe([]);
});
