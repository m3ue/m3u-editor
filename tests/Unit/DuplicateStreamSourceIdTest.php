<?php

/**
 * Unit tests for the collision-relative source_id hashing in
 * ProcessM3uImport::assignCollisionSourceIds().
 */

use App\Jobs\ProcessM3uImport;

it('assigns the base md5 hash to the first occurrence of a source_key', function () {
    $sourceKey = 'CCTV-1CCTV1CCTV';

    $result = ProcessM3uImport::assignCollisionSourceIds(collect([
        ['source_key' => $sourceKey, 'playlist_id' => 1],
    ]))->all();

    expect($result[0]['source_id'])->toBe(md5($sourceKey));
});

it('assigns :dup:N suffixes to subsequent duplicate source_keys', function () {
    $sourceKey = 'CCTV-1CCTV1CCTV';

    $result = ProcessM3uImport::assignCollisionSourceIds(collect([
        ['source_key' => $sourceKey, 'playlist_id' => 1, 'url' => 'https://example.com?streamid=aaa'],
        ['source_key' => $sourceKey, 'playlist_id' => 1, 'url' => 'https://example.com?streamid=bbb'],
        ['source_key' => $sourceKey, 'playlist_id' => 1, 'url' => 'https://example.com?streamid=ccc'],
    ]))->all();

    expect($result[0]['source_id'])->toBe(md5($sourceKey))
        ->and($result[1]['source_id'])->toBe(md5($sourceKey.':dup:1'))
        ->and($result[2]['source_id'])->toBe(md5($sourceKey.':dup:2'));
});

it('keeps counting across 50-item chunk boundaries when hashed before chunking', function () {
    $channels = collect(range(1, 120))->map(fn (int $i) => [
        // Every 40th channel shares a title, so duplicates land in different chunks.
        'source_key' => $i % 40 === 0 ? 'DupDupNews' : "Ch {$i}Ch {$i}News",
        'playlist_id' => 1,
    ]);

    $sourceIds = ProcessM3uImport::assignCollisionSourceIds($channels)
        ->chunk(50)
        ->flatMap(fn ($chunk) => $chunk->pluck('source_id'))
        ->all();

    expect(count(array_unique($sourceIds)))->toBe(120)
        ->and($sourceIds)->toContain(md5('DupDupNews'), md5('DupDupNews:dup:1'), md5('DupDupNews:dup:2'));
});

it('is backwards-compatible - existing channels keep their source_id on re-import', function () {
    $sourceKey = 'BBC OneBBC OneNews';

    $result = ProcessM3uImport::assignCollisionSourceIds(collect([
        ['source_key' => $sourceKey, 'playlist_id' => 2],
    ]))->all();

    expect($result[0]['source_id'])->toBe(md5($sourceKey));
});

it('removes source_key from the channel array after hashing', function () {
    $result = ProcessM3uImport::assignCollisionSourceIds(collect([
        ['source_key' => 'some key', 'playlist_id' => 1],
    ]))->all();

    expect($result[0])->not->toHaveKey('source_key');
});

it('assigns the same source_id to a duplicate-named entry regardless of file order when it carries a stable tvg-id (issue #1664)', function () {
    $sourceKey = 'NewsNewsNews';

    $forward = ProcessM3uImport::assignCollisionSourceIds(collect([
        ['source_key' => $sourceKey, 'stream_id' => 'synthetic-a', 'playlist_id' => 1, 'url' => 'http://example.test/a.ts'],
        ['source_key' => $sourceKey, 'stream_id' => 'synthetic-b', 'playlist_id' => 1, 'url' => 'http://example.test/b.ts'],
    ]))->all();

    $reversed = ProcessM3uImport::assignCollisionSourceIds(collect([
        ['source_key' => $sourceKey, 'stream_id' => 'synthetic-b', 'playlist_id' => 1, 'url' => 'http://example.test/b.ts'],
        ['source_key' => $sourceKey, 'stream_id' => 'synthetic-a', 'playlist_id' => 1, 'url' => 'http://example.test/a.ts'],
    ]))->all();

    $forwardIdsByUrl = collect($forward)->pluck('source_id', 'url');
    $reversedIdsByUrl = collect($reversed)->pluck('source_id', 'url');

    // Each entry gets the same source_id under its own URL no matter which position
    // it appears at in the file, so a reorder-only reimport can't swap which existing
    // channel row the a.ts/b.ts data lands on.
    expect($forwardIdsByUrl['http://example.test/a.ts'])->toBe($reversedIdsByUrl['http://example.test/a.ts'])
        ->and($forwardIdsByUrl['http://example.test/b.ts'])->toBe($reversedIdsByUrl['http://example.test/b.ts'])
        ->and($forwardIdsByUrl['http://example.test/a.ts'])->not->toBe($forwardIdsByUrl['http://example.test/b.ts']);
});

it('still disambiguates colliding entries by encounter order when none of them carry a tvg-id', function () {
    $sourceKey = 'NewsNewsNews';

    $result = ProcessM3uImport::assignCollisionSourceIds(collect([
        ['source_key' => $sourceKey, 'playlist_id' => 1, 'url' => 'http://example.test/a.ts'],
        ['source_key' => $sourceKey, 'playlist_id' => 1, 'url' => 'http://example.test/b.ts'],
    ]))->all();

    expect($result[0]['source_id'])->toBe(md5($sourceKey))
        ->and($result[1]['source_id'])->toBe(md5($sourceKey.':dup:1'));
});

it('does not change the hash for a non-colliding entry that happens to carry a tvg-id', function () {
    $sourceKey = 'BBC OneBBC OneNews';

    $result = ProcessM3uImport::assignCollisionSourceIds(collect([
        ['source_key' => $sourceKey, 'stream_id' => 'bbc-one', 'playlist_id' => 1],
    ]))->all();

    expect($result[0]['source_id'])->toBe(md5($sourceKey));
});

it('passes through Xtream channels that have no source_key unchanged', function () {
    $result = ProcessM3uImport::assignCollisionSourceIds(collect([
        ['source_id' => 'stream-12345', 'playlist_id' => 1, 'url' => 'https://provider.com/12345'],
    ]))->all();

    expect($result[0]['source_id'])->toBe('stream-12345')
        ->and($result[0])->not->toHaveKey('source_key');
});

/**
 * An existing channel row as assignCollisionSourceIds() matches it.
 *
 * @return array{source_key: string, source_id: string, stream_id: ?string, url: ?string, is_vod: bool}
 */
function collisionRow(string $sourceKey, string $sourceId, ?string $tvgId, string $url): array
{
    return ['source_key' => $sourceKey, 'source_id' => $sourceId, 'stream_id' => $tvgId, 'url' => $url, 'is_vod' => false];
}

function collisionEntry(string $sourceKey, ?string $tvgId, string $url): array
{
    return ['source_key' => $sourceKey, 'stream_id' => $tvgId, 'url' => $url, 'is_vod' => false, 'playlist_id' => 1];
}

it('keeps the rows an older hashing scheme created when the feed is reordered', function () {
    $key = 'NewsNewsNews';
    $rows = [
        collisionRow($key, md5($key), 'tvg-a', 'http://example.test/a.ts'),
        collisionRow($key, md5($key.':dup:1'), 'tvg-b', 'http://example.test/b.ts'),
    ];

    $result = ProcessM3uImport::assignCollisionSourceIds(collect([
        collisionEntry($key, 'tvg-b', 'http://example.test/b.ts'),
        collisionEntry($key, 'tvg-a', 'http://example.test/a.ts'),
    ]), $rows)->pluck('source_id', 'url')->all();

    expect($result)->toBe([
        'http://example.test/b.ts' => md5($key.':dup:1'),
        'http://example.test/a.ts' => md5($key),
    ]);
});

it('keeps the existing row when a duplicate of its name appears', function () {
    $key = 'NewsNewsNews';
    $rows = [collisionRow($key, md5($key), 'tvg-a', 'http://example.test/a.ts')];

    $result = ProcessM3uImport::assignCollisionSourceIds(collect([
        collisionEntry($key, 'tvg-c', 'http://example.test/c.ts'),
        collisionEntry($key, 'tvg-a', 'http://example.test/a.ts'),
    ]), $rows)->pluck('source_id', 'url')->all();

    expect($result['http://example.test/a.ts'])->toBe(md5($key))
        ->and($result['http://example.test/c.ts'])->toBe(md5($key.'|sid:tvg-c'));
});

it('keeps the existing row when its duplicate disappears', function () {
    $key = 'NewsNewsNews';
    $rows = [
        collisionRow($key, md5($key.'|sid:tvg-a'), 'tvg-a', 'http://example.test/a.ts'),
        collisionRow($key, md5($key.'|sid:tvg-b'), 'tvg-b', 'http://example.test/b.ts'),
    ];

    $result = ProcessM3uImport::assignCollisionSourceIds(collect([
        collisionEntry($key, 'tvg-a', 'http://example.test/a.ts'),
    ]), $rows)->all();

    expect($result[0]['source_id'])->toBe(md5($key.'|sid:tvg-a'));
});

it('never hands a dropped entry\'s row to a new duplicate with a different tvg-id', function () {
    $key = 'NewsNewsNews';
    $rows = [
        collisionRow($key, md5($key), 'tvg-a', 'http://example.test/a.ts'),
        collisionRow($key, md5($key.':dup:1'), 'tvg-b', 'http://example.test/b.ts'),
    ];

    $result = ProcessM3uImport::assignCollisionSourceIds(collect([
        collisionEntry($key, 'tvg-a', 'http://example.test/a.ts'),
        collisionEntry($key, 'tvg-c', 'http://example.test/c.ts'),
    ]), $rows)->pluck('source_id', 'url')->all();

    expect($result['http://example.test/a.ts'])->toBe(md5($key))
        ->and($result['http://example.test/c.ts'])->not->toBeIn([md5($key), md5($key.':dup:1')]);
});

it('follows the URL of duplicate-named entries without a tvg-id', function () {
    $key = 'NewsNewsNews';
    $rows = [
        collisionRow($key, md5($key), null, 'http://example.test/a.ts'),
        collisionRow($key, md5($key.':dup:1'), null, 'http://example.test/b.ts'),
    ];

    $result = ProcessM3uImport::assignCollisionSourceIds(collect([
        collisionEntry($key, null, 'http://example.test/b.ts'),
        collisionEntry($key, null, 'http://example.test/a.ts'),
    ]), $rows)->pluck('source_id', 'url')->all();

    expect($result)->toBe([
        'http://example.test/b.ts' => md5($key.':dup:1'),
        'http://example.test/a.ts' => md5($key),
    ]);
});
