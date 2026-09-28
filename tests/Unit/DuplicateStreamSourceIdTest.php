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

it('passes through Xtream channels that have no source_key unchanged', function () {
    $result = ProcessM3uImport::assignCollisionSourceIds(collect([
        ['source_id' => 'stream-12345', 'playlist_id' => 1, 'url' => 'https://provider.com/12345'],
    ]))->all();

    expect($result[0]['source_id'])->toBe('stream-12345')
        ->and($result[0])->not->toHaveKey('source_key');
});
